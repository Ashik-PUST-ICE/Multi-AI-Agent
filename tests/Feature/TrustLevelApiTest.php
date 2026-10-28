<?php

namespace Tests\Feature;

use App\Agents\ResearchAgent;
use App\Llm\ChatResponse;
use App\Llm\LlmProvider;
use App\Llm\UserLlmProviderResolver;
use App\Models\AgentTrust;
use App\Models\User;
use App\Services\TrustLevelService;
use App\Tools\ToolInterface;
use App\Tools\ToolRegistry;
use App\Tools\ToolTrustLevelException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrustLevelApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_trust_overview_uses_the_configured_least_privilege_defaults(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/trust')
            ->assertOk()
            ->assertJsonCount(4, 'agents');

        foreach ($response->json('agents') as $agent) {
            $expectedLevel = $agent['agent_name'] === 'Research' ? 1 : 0;
            $this->assertSame($expectedLevel, $agent['trust_level']);
        }

        $this->assertDatabaseCount('agent_trust', 0);
    }

    public function test_trust_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/trust')->assertUnauthorized();
        $this->putJson('/api/v1/trust/Research', [
            'trust_level' => 1,
            'reason' => 'An unauthenticated caller cannot change trust.',
        ])->assertUnauthorized();
    }

    public function test_user_can_change_own_agent_trust_and_review_audit_history(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/trust/Research', [
            'trust_level' => 0,
            'reason' => 'Keep research read access disabled.',
        ])->assertOk();

        $this->putJson('/api/v1/trust/Research', [
            'trust_level' => 1,
            'reason' => 'Allow read-only research tools.',
        ])->assertOk()
            ->assertJsonPath('agent_name', 'Research')
            ->assertJsonPath('trust_level', 1);

        $this->getJson('/api/v1/trust')
            ->assertOk()
            ->assertJsonPath('agents.1.agent_name', 'Research')
            ->assertJsonPath('agents.1.trust_level', 1)
            ->assertJsonPath('history.0.change_type', 'user')
            ->assertJsonPath('history.0.from_level', 0)
            ->assertJsonPath('history.0.to_level', 1);

        $this->assertDatabaseHas('agent_trust_history', [
            'user_id' => $user->id,
            'agent_name' => 'Research',
            'changed_by' => $user->id,
            'reason' => 'Allow read-only research tools.',
        ]);
        $this->assertDatabaseMissing('agent_trust', [
            'user_id' => $otherUser->id,
            'agent_name' => 'Research',
        ]);
    }

    public function test_invalid_levels_and_unregistered_agents_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/trust/Research', [
            'trust_level' => 5,
            'reason' => 'Attempt invalid level.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['trust_level']);

        $this->putJson('/api/v1/trust/UnknownAgent', [
            'trust_level' => 1,
            'reason' => 'Unknown agents are not configurable.',
        ])->assertNotFound();
    }

    public function test_trust_violation_automatically_reduces_level_and_records_history(): void
    {
        $user = User::factory()->create();
        AgentTrust::query()->create([
            'user_id' => $user->id,
            'agent_name' => 'Research',
            'trust_level' => 2,
        ]);

        app(TrustLevelService::class)->recordViolation(
            $user,
            'Research',
            'web_search',
            3,
            2,
            null,
        );

        $this->assertDatabaseHas('agent_trust', [
            'user_id' => $user->id,
            'agent_name' => 'Research',
            'trust_level' => 1,
        ]);
        $this->assertDatabaseHas('agent_trust_history', [
            'user_id' => $user->id,
            'agent_name' => 'Research',
            'from_level' => 2,
            'to_level' => 1,
            'change_type' => 'automatic',
        ]);

        app(TrustLevelService::class)->recordViolation(
            $user,
            'Research',
            'web_search',
            3,
            0,
            null,
        );
        app(TrustLevelService::class)->recordViolation(
            $user,
            'Research',
            'web_search',
            3,
            0,
            null,
        );
        $this->assertDatabaseHas('agent_trust', [
            'user_id' => $user->id,
            'agent_name' => 'Research',
            'trust_level' => 0,
        ]);
        $this->assertDatabaseHas('agent_trust_history', [
            'user_id' => $user->id,
            'agent_name' => 'Research',
            'from_level' => 0,
            'to_level' => 0,
            'change_type' => 'violation',
        ]);
    }

    public function test_agent_tool_violation_is_blocked_and_automatically_demotes_trust(): void
    {
        $user = User::factory()->create();
        $trust = AgentTrust::query()->create([
            'user_id' => $user->id,
            'agent_name' => 'Research',
            'trust_level' => 1,
        ]);
        $task = $user->tasks()->create(['title' => 'Trust enforcement']);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'mode' => TASK_MODE_SANDBOX,
            'input' => [],
            'status' => AGENT_RUN_STATUS_RUNNING,
        ]);
        $tool = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'web_search';
            }

            public function description(): string
            {
                return 'Search the web.';
            }

            public function requiresApproval(): bool
            {
                return false;
            }

            public function minimumTrustLevel(): int
            {
                return 2;
            }

            public function inputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                    'required' => ['query'],
                ];
            }

            public function execute(array $input): array
            {
                return [];
            }

            public function simulate(array $input): array
            {
                return ['simulated' => true];
            }
        };
        $provider = new class implements LlmProvider
        {
            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                return new ChatResponse(null, [[
                    'id' => 'call-1',
                    'name' => 'web_search',
                    'arguments' => ['query' => 'test'],
                ]], [], 'tool_calls');
            }
        };
        $resolver = \Mockery::mock(UserLlmProviderResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([
            'provider' => $provider,
            'model' => 'test-model',
            'routing' => [],
            'fallbacks' => [],
        ]);
        $specification = [
            'system_prompt' => 'Research safely.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['prompt', 'context'],
                'properties' => [
                    'prompt' => ['type' => 'string', 'minLength' => 1],
                    'context' => ['type' => 'object'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['text'],
                'properties' => ['text' => ['type' => 'string', 'minLength' => 1]],
            ],
            'allowed_tools' => ['web_search'],
            'max_steps' => 2,
            'max_tokens' => 1000,
        ];
        $toolRegistry = new ToolRegistry;
        $toolRegistry->register($tool);
        $agent = new ResearchAgent(
            null,
            $toolRegistry,
            TASK_MODE_SANDBOX,
            $resolver,
            null,
            $specification,
        );

        try {
            $agent->runForUser($user, 'Research a test query.', [], $agentRun);
            $this->fail('The agent tool call must be blocked by its minimum trust level.');
        } catch (ToolTrustLevelException $exception) {
            $this->assertSame(2, $exception->requiredLevel);
            $this->assertSame(1, $exception->currentLevel);
        }

        $this->assertSame(0, $trust->fresh()->trust_level);
        $this->assertDatabaseHas('agent_trust_history', [
            'user_id' => $user->id,
            'agent_name' => 'Research',
            'from_level' => 1,
            'to_level' => 0,
            'change_type' => 'automatic',
        ]);
        $this->assertDatabaseHas('run_events', [
            'agent_run_id' => $agentRun->id,
            'event_type' => 'agent.trust_violation',
        ]);
    }
}
