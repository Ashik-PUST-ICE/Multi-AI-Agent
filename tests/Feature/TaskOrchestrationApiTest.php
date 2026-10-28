<?php

namespace Tests\Feature;

use App\Jobs\RunAgentJob;
use App\Llm\ProviderCatalog;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentRunService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class TaskOrchestrationApiTest extends TestCase
{
    use DatabaseMigrations;

    public function test_task_submission_is_authenticated_and_queues_the_routed_agent_run(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/tasks', [
            'title' => 'Research task',
            'prompt' => 'Research a topic.',
        ])->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks', [
            'title' => 'Research task',
            'prompt' => 'Research a topic.',
        ])->assertAccepted()
            ->assertJsonPath('task.title', 'Research task')
            ->assertJsonPath('task.status', TASK_STATUS_PENDING)
            ->assertJsonPath('task.mode', TASK_MODE_SANDBOX)
            ->assertJsonPath('task.routing.strategy', 'single_agent')
            ->assertJsonPath('task.routing.agent_sequence.0', 'Research');

        $task = Task::query()->firstOrFail();
        $agentRun = $task->agentRuns()->firstOrFail();

        $this->assertSame('Research', $agentRun->agent_name);
        $this->assertSame('Research a topic.', $agentRun->input['payload']['prompt']);
        $this->assertSame('user', $agentRun->input['from']);
        $this->assertSame('Research', $agentRun->input['to']);
        $this->assertSame(AGENT_MESSAGE_SCHEMA_VERSION, $agentRun->input['schema_version']);
        Queue::assertPushed(RunAgentJob::class);
    }

    public function test_task_estimate_requires_authentication_and_explains_single_or_multi_agent_routing(): void
    {
        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Research a topic.',
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Research a topic.',
        ])->assertOk()
            ->assertJsonPath('routing.strategy', 'single_agent')
            ->assertJsonPath('routing.agent_sequence.0', 'Research');

        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Research and write a summary with an independent review.',
        ])->assertOk()
            ->assertJsonPath('routing.strategy', 'multi_agent')
            ->assertJsonPath('routing.agent_sequence', ['Orchestrator', 'Research', 'Writer', 'Reviewer']);
    }

    public function test_single_agent_task_executes_and_returns_its_answer(): void
    {
        Queue::fake();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Research answer.'],
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);
        Sanctum::actingAs($user = $this->userWithApprovedProvider());

        $this->postJson('/api/v1/tasks', [
            'title' => 'Research task',
            'prompt' => 'Research a topic.',
        ])->assertAccepted();

        $task = Task::query()->firstOrFail();
        $agentRun = $task->agentRuns()->firstOrFail();
        app(AgentRunService::class)->run($agentRun->id);

        $task->refresh();
        $this->assertSame(TASK_STATUS_COMPLETED, $task->status);
        $this->assertSame('Research answer.', $task->result['answer']);
        $this->assertSame(1, $task->agentRuns()->count());
        $this->assertGreaterThan(0, $task->agentRuns()->firstOrFail()->token_usage['total_tokens']);
        Http::assertSentCount(1);
    }

    public function test_single_and_multi_agent_routes_compare_results_and_recorded_token_usage(): void
    {
        Queue::fake();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'A comparable answer.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 3,
                    'completion_tokens' => 2,
                    'total_tokens' => 5,
                ],
            ]),
        ]);
        Sanctum::actingAs($user = $this->userWithApprovedProvider());

        $this->postJson('/api/v1/tasks', [
            'title' => 'Single route comparison',
            'prompt' => 'Research a topic.',
        ])->assertAccepted()->assertJsonPath('task.routing.strategy', 'single_agent');
        $singleTask = Task::query()->where('title', 'Single route comparison')->firstOrFail();

        $this->postJson('/api/v1/tasks', [
            'title' => 'Multi route comparison',
            'prompt' => 'Research and write a summary with an independent review.',
        ])->assertAccepted()->assertJsonPath('task.routing.strategy', 'multi_agent');
        $multiTask = Task::query()->where('title', 'Multi route comparison')->firstOrFail();

        $agentRunService = app(AgentRunService::class);
        $agentRunService->run($singleTask->agentRuns()->firstOrFail()->id);
        foreach (['Orchestrator', 'Research', 'Writer', 'Reviewer'] as $agentName) {
            $agentRunService->run(
                $multiTask->agentRuns()->where('agent_name', $agentName)->firstOrFail()->id,
            );
        }

        $singleTask->refresh();
        $multiTask->refresh();
        $singleTokenUsage = (int) $singleTask->agentRuns()->firstOrFail()->token_usage['total_tokens'];
        $multiTokenUsage = (int) $multiTask->agentRuns()
            ->get()
            ->sum(static fn (AgentRun $agentRun): int => (int) ($agentRun->token_usage['total_tokens'] ?? 0));

        $this->assertSame('A comparable answer.', $singleTask->result['answer']);
        $this->assertSame($singleTask->result['answer'], $multiTask->result['answer']);
        $this->assertSame(1, $singleTask->agentRuns()->count());
        $this->assertSame(4, $multiTask->agentRuns()->count());
        $this->assertGreaterThan($singleTokenUsage, $multiTokenUsage);
        $this->assertSame(20, $multiTokenUsage);
    }

    public function test_task_submission_rejects_prompts_that_cannot_fit_the_agent_token_budget(): void
    {
        $user = $this->userWithApprovedProvider();
        $user->llmSettings()->update(['model_pricing' => []]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks', [
            'title' => 'Oversized prompt',
            'prompt' => str_repeat('Long prompt content. ', 500),
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'The estimated input leaves no room for an agent response within the configured token budget.',
            );

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_task_status_is_only_visible_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $task = $owner->tasks()->create([
            'title' => 'Private task',
            'input' => ['prompt' => 'Owner-only prompt.'],
            'mode' => TASK_MODE_SANDBOX,
            'status' => TASK_STATUS_PENDING,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/tasks/{$task->id}")->assertNotFound();

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('task.title', 'Private task')
            ->assertJsonPath('task.agent_runs', []);
    }

    public function test_task_list_contains_only_the_authenticated_users_tasks(): void
    {
        $owner = User::factory()->create();
        $owner->tasks()->create([
            'title' => 'My task',
            'input' => ['prompt' => 'My prompt.'],
        ]);
        User::factory()->create()->tasks()->create([
            'title' => 'Another user task',
            'input' => ['prompt' => 'Private prompt.'],
        ]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'My task')
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_job_runs_the_four_agent_pipeline_and_completes_the_task(): void
    {
        Queue::fake();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Stage completed.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 3,
                    'completion_tokens' => 2,
                    'total_tokens' => 5,
                ],
            ]),
        ]);

        $user = $this->userWithApprovedProvider();
        $task = $user->tasks()->create([
            'title' => 'Pipeline task',
            'input' => ['prompt' => 'Complete a test task.'],
            'mode' => TASK_MODE_SANDBOX,
            'status' => TASK_STATUS_PENDING,
        ]);
        $firstAgentRun = $task->agentRuns()->create([
            'agent_name' => 'Orchestrator',
            'input' => ['prompt' => 'Complete a test task.', 'context' => []],
            'status' => AGENT_RUN_STATUS_PENDING,
        ]);

        $agentRunService = app(AgentRunService::class);
        $agentRunService->run($firstAgentRun->id);

        foreach (['Research', 'Writer', 'Reviewer'] as $agentName) {
            $nextAgentRun = $task->agentRuns()
                ->where('agent_name', $agentName)
                ->firstOrFail();
            $agentRunService->run($nextAgentRun->id);
        }

        $task->refresh();
        $this->assertSame(TASK_STATUS_COMPLETED, $task->status);
        $this->assertSame('Stage completed.', $task->result['answer']);
        $this->assertSame(4, $task->agentRuns()->where('status', AGENT_RUN_STATUS_COMPLETED)->count());
        Http::assertSentCount(4);
    }

    public function test_exhausted_job_failure_marks_the_agent_run_and_task_failed(): void
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'Failing task',
            'input' => ['prompt' => 'Test failure handling.'],
            'mode' => TASK_MODE_SANDBOX,
            'status' => TASK_STATUS_RUNNING,
        ]);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Orchestrator',
            'input' => ['prompt' => 'Test failure handling.', 'context' => []],
            'status' => AGENT_RUN_STATUS_RUNNING,
        ]);

        $job = new RunAgentJob($agentRun->id);
        $job->failed(new RuntimeException('LLM provider request failed with HTTP status 502.'));

        $agentRun->refresh();
        $task->refresh();
        $this->assertSame(AGENT_RUN_STATUS_FAILED, $agentRun->status);
        $this->assertSame(TASK_STATUS_FAILED, $task->status);
        $this->assertSame('LLM provider request failed with HTTP status 502.', $task->error_message);
    }

    public function test_agent_job_uses_a_bounded_timeout(): void
    {
        config(['llm.agent_timeout' => 45]);
        $job = new RunAgentJob(1);

        $this->assertSame(45, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([5, 15, 30], $job->backoff);
    }

    private function userWithApprovedProvider(): User
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
        $user->apiKeys()->create([
            'provider' => $providerId,
            'api_key' => 'test-provider-key',
        ]);

        return $user;
    }
}
