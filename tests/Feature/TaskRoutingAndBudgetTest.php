<?php

namespace Tests\Feature;

use App\Agents\ComplexityRouter;
use App\Llm\ChatResponse;
use App\Llm\ProviderCatalog;
use App\Models\User;
use App\Services\TaskCostEstimator;
use App\Services\TaskTokenBudgetService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class TaskRoutingAndBudgetTest extends TestCase
{
    use DatabaseMigrations;

    public function test_router_defaults_to_one_agent_unless_multiple_roles_are_explicit(): void
    {
        $router = app(ComplexityRouter::class);

        $this->assertSame(['Research'], $router->route('Research a topic.')['agent_sequence']);
        $this->assertSame(['Writer'], $router->route('Write a short welcome message.')['agent_sequence']);
        $this->assertSame(
            ['Orchestrator', 'Research', 'Writer', 'Reviewer'],
            $router->route('Research and write a summary with an independent review.')['agent_sequence'],
        );
    }

    public function test_cost_estimate_uses_user_entered_model_pricing_and_never_fabricates_rates(): void
    {
        $user = $this->userWithApprovedModel();
        $estimator = app(TaskCostEstimator::class);
        $unpricedEstimate = $estimator->estimate($user, 'Write a concise response.', ['Writer']);

        $this->assertSame('unavailable', $unpricedEstimate['status']);
        $this->assertTrue($unpricedEstimate['can_complete']);
        $this->assertNull($unpricedEstimate['maximum_cost']);

        $settings = $user->llmSettings;
        $settings->model_pricing = [[
            'provider' => $settings->provider,
            'model' => $settings->model,
            'currency' => 'USD',
            'input_cost_per_million' => 1,
            'output_cost_per_million' => 2,
        ]];
        $settings->save();
        $pricedEstimate = $estimator->estimate($user, 'Write a concise response.', ['Writer']);

        $this->assertSame('available', $pricedEstimate['status']);
        $this->assertSame('USD', $pricedEstimate['currency']);
        $this->assertGreaterThan(0, $pricedEstimate['maximum_cost']);
        $this->assertSame(3000, $pricedEstimate['agents'][0]['maximum_total_tokens']);
    }

    public function test_preflight_compares_single_agent_and_multi_agent_token_and_cost_ceilings(): void
    {
        $user = $this->userWithApprovedModel();
        $settings = $user->llmSettings;
        $settings->model_pricing = [[
            'provider' => $settings->provider,
            'model' => $settings->model,
            'currency' => 'USD',
            'input_cost_per_million' => 1,
            'output_cost_per_million' => 2,
        ]];
        $settings->save();
        $router = app(ComplexityRouter::class);
        $estimator = app(TaskCostEstimator::class);
        $singleRouting = $router->route('Write a summary about renewable energy.');
        $multiRouting = $router->route('Research and write a summary about renewable energy with an independent review.');
        $singleEstimate = $estimator->estimate($user, 'Write a summary about renewable energy.', $singleRouting['agent_sequence']);
        $multiEstimate = $estimator->estimate($user, 'Research and write a summary about renewable energy with an independent review.', $multiRouting['agent_sequence']);

        $this->assertSame('single_agent', $singleRouting['strategy']);
        $this->assertSame('multi_agent', $multiRouting['strategy']);
        $this->assertSame(3000, $singleEstimate['estimated_tokens']);
        $this->assertSame(10000, $multiEstimate['estimated_tokens']);
        $this->assertGreaterThan($singleEstimate['maximum_cost'], $multiEstimate['maximum_cost']);
    }

    public function test_token_budget_overrun_is_persisted_before_it_stops_the_run(): void
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'Budget test',
            'input' => ['prompt' => 'Test hard budget enforcement.'],
            'mode' => TASK_MODE_SANDBOX,
            'status' => TASK_STATUS_RUNNING,
        ]);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Writer',
            'input' => ['prompt' => 'Test hard budget enforcement.', 'context' => []],
            'status' => AGENT_RUN_STATUS_RUNNING,
        ]);

        try {
            app(TaskTokenBudgetService::class)->recordUsage(
                $agentRun,
                ['input_tokens' => 7, 'output_tokens' => 5, 'total_tokens' => 12],
                10,
            );
            $this->fail('The per-agent token limit should reject the over-budget usage.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The agent exceeded its configured token budget.', $exception->getMessage());
        }

        $this->assertSame(12, $agentRun->fresh()->token_usage['total_tokens']);
    }

    public function test_partial_provider_usage_is_preserved_and_missing_fields_are_estimated(): void
    {
        $usage = app(TaskTokenBudgetService::class)->responseUsage(
            [['role' => 'user', 'content' => 'A request with no exact usage metadata.']],
            new ChatResponse('A short answer.', [], ['output_tokens' => 2], 'stop'),
        );

        $this->assertGreaterThan(0, $usage['input_tokens']);
        $this->assertSame(2, $usage['output_tokens']);
        $this->assertSame($usage['input_tokens'] + 2, $usage['total_tokens']);
    }

    public function test_missing_provider_settings_are_reported_by_the_estimate_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Write a short response.',
        ])->assertOk()
            ->assertJsonPath('cost_estimate.status', 'unavailable')
            ->assertJsonPath('cost_estimate.reason', 'Save provider settings and approve a model before estimating cost.');
    }

    private function userWithApprovedModel(): User
    {
        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $modelId = array_key_first($catalog->provider($providerId)['models']);
        $user = User::factory()->create();

        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $modelId,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $modelId],
            ],
            'agent_models' => [],
        ]);

        return $user;
    }
}
