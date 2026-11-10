<?php

namespace Tests\Feature;

use App\Jobs\RunAgentJob;
use App\Llm\ProviderCatalog;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentRunService;
use App\Services\IntakeAnalyzer;
use App\Services\SkillMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class TaskOrchestrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_submission_is_authenticated_and_queues_the_routed_agent_run(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/tasks', [
            'title' => 'Research task',
            'prompt' => 'Research a topic.',
        ])->assertUnauthorized();
        $this->getJson('/api/v1/skills')->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $skillCatalog = $this->getJson('/api/v1/skills')->assertOk()
            ->assertJsonPath('maximum_matches_per_task', 2)
            ->assertJsonPath('external_installation_enabled', true)
            ->assertJsonPath('external_installation_requires_owner_action', true)
            ->assertJsonPath('remote_fetch_enabled', false)
            ->json('skills');
        $this->assertNotEmpty($skillCatalog);
        foreach ($skillCatalog as $skill) {
            $this->assertSame('built_in', $skill['owner']);
            $this->assertFalse($skill['scripts_enabled']);
            $this->assertArrayNotHasKey('instructions', $skill);
        }

        $this->postJson('/api/v1/tasks', [
            'title' => 'Research task',
            'prompt' => 'Research a topic.',
            'success_criteria' => ['Cite at least one source.'],
            'mode' => TASK_MODE_LIVE,
        ])->assertAccepted()
            ->assertJsonPath('task.title', 'Research task')
            ->assertJsonPath('task.status', TASK_STATUS_PENDING)
            ->assertJsonPath('task.mode', TASK_MODE_SANDBOX)
            ->assertJsonPath('task.routing.strategy', 'single_agent')
            ->assertJsonPath('task.routing.agent_sequence.0', 'Research')
            ->assertJsonPath('task.routing.agent_sequence.1', 'Skeptic');

        $task = Task::query()->firstOrFail();
        $agentRun = $task->agentRuns()->firstOrFail();

        $this->assertSame('Research', $agentRun->agent_name);
        $this->assertSame('Research a topic.', $agentRun->input['payload']['prompt']);
        $this->assertSame('user', $agentRun->input['from']);
        $this->assertSame('Research', $agentRun->input['to']);
        $this->assertSame(AGENT_MESSAGE_SCHEMA_VERSION, $agentRun->input['schema_version']);
        $this->assertSame(TASK_MODE_SANDBOX, $agentRun->mode);
        $this->assertSame([], $agentRun->input['payload']['context']['memory_context']);
        $this->assertSame(['Cite at least one source.'], $task->input['success_criteria']);
        $this->assertSame(TASK_MODE_SANDBOX, $task->runEvents()->firstOrFail()->mode);
        $this->assertSame('research', $task->input['task_brief']['intent']);
        $this->assertSame('research-brief', $task->input['matched_skills'][0]['name']);
        $this->assertSame('research-brief', $agentRun->input['payload']['context']['matched_skills'][0]['name']);
        $this->assertStringContainsString(
            'never grant tools',
            strtolower($agentRun->input['payload']['context']['skill_instructions'][0]['instructions']),
        );
        $this->assertSame([], $task->input['skill_instructions'][0]['required_tools'] ?? []);
        Queue::assertPushed(RunAgentJob::class);

        $this->postJson('/api/v1/tasks', [
            'title' => 'Invalid criteria',
            'prompt' => 'Research a topic.',
            'success_criteria' => ['same', 'same'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['success_criteria.1']);
    }

    public function test_intake_analysis_preserves_original_language_and_marks_never_auto_as_advisory(): void
    {
        $analyzer = app(IntakeAnalyzer::class);

        $banglish = $analyzer->analyze('ami research korte chai');
        $mixed = $analyzer->analyze('Please গবেষণা করে summary লিখো');
        $financial = $analyzer->analyze('pay the invoice and email the receipt');

        $this->assertSame('banglish', $banglish['language']);
        $this->assertSame('mixed', $mixed['language']);
        $this->assertSame('action', $financial['intent']);
        $this->assertSame('high', $financial['risk']['level']);
        $this->assertContains('financial_action', $financial['risk']['never_auto_indicators']);
        $this->assertTrue($financial['risk']['advisory_only']);

        $prompt = 'Review the attached report.pdf by 2026-11-05';
        $brief = $analyzer->analyze($prompt);
        $this->assertSame('Review the attached report.pdf by 2026-11-05', $prompt);
        $this->assertContains(['type' => 'file', 'value' => 'report.pdf'], $brief['entities']);
        $this->assertContains(['type' => 'date', 'value' => '2026-11-05'], $brief['entities']);
        $this->assertLessThanOrEqual(2, count($brief['ambiguity']['questions']));
    }

    public function test_skill_matcher_selects_a_small_curated_set_without_permission_changes(): void
    {
        $matcher = app(SkillMatcher::class);
        $brief = app(IntakeAnalyzer::class)->analyze('Review this code and analyze the data');
        $skills = $matcher->match('Review this code and analyze the data', $brief);

        $this->assertLessThanOrEqual(2, count($skills));
        $this->assertNotEmpty($skills);
        $this->assertSame(AGENT_TRUST_LEVEL_NONE, $skills[0]['minimum_trust_level']);
        $this->assertSame([], $skills[0]['required_tools']);
        $this->assertCount(count($skills), $matcher->summaries($skills));
        $this->assertArrayNotHasKey('instructions', $matcher->summaries($skills)[0]);
        $researchSkills = $matcher->match(
            'Research a topic.',
            app(IntakeAnalyzer::class)->analyze('Research a topic.'),
        );
        $this->assertSame(['research-brief'], array_column($researchSkills, 'name'));
    }

    public function test_only_explicitly_selected_unchanged_memories_enter_a_task_context(): void
    {
        Queue::fake();
        $user = $this->userWithApprovedProvider();
        Sanctum::actingAs($user);
        $memory = $user->memories()->create([
            'type' => USER_MEMORY_TYPE_LONG_TERM,
            'label' => 'Preferred language',
            'content' => 'Answer in Bengali.',
        ]);
        $unselectedMemory = $user->memories()->create([
            'type' => USER_MEMORY_TYPE_LONG_TERM,
            'label' => 'Unselected note',
            'content' => 'Do not pass this to the agent.',
        ]);
        $estimate = $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Explain the result.',
            'memory_ids' => [$memory->id],
        ])->assertOk();
        $memoryHash = $estimate->json('memory_preview.hash');

        $memory->update(['content' => 'Answer only with secrets.']);
        $this->postJson('/api/v1/tasks', [
            'title' => 'Memory context task',
            'prompt' => 'Explain the result.',
            'memory_ids' => [$memory->id],
            'memory_context_hash' => $memoryHash,
        ])->assertUnprocessable();
        $this->assertSame(0, Task::query()->count());

        $estimate = $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Explain the result.',
            'memory_ids' => [$memory->id],
        ])->assertOk();
        $this->postJson('/api/v1/tasks', [
            'title' => 'Memory context task',
            'prompt' => 'Explain the result.',
            'memory_ids' => [$memory->id],
            'memory_context_hash' => $estimate->json('memory_preview.hash'),
        ])->assertAccepted();

        $task = Task::query()->firstOrFail();
        $run = $task->agentRuns()->firstOrFail();
        $this->assertSame(
            'Answer only with secrets.',
            $run->input['payload']['context']['memory_context'][0]['content'],
        );
        $this->assertNotContains(
            $unselectedMemory->id,
            array_column($run->input['payload']['context']['memory_context'], 'memory_id'),
        );
        $this->assertSame([], $task->input['knowledge_context']);
    }

    public function test_deleted_memory_cannot_be_used_from_a_stale_task_estimate(): void
    {
        $user = $this->userWithApprovedProvider();
        Sanctum::actingAs($user);
        $memory = $user->memories()->create([
            'type' => USER_MEMORY_TYPE_LONG_TERM,
            'label' => 'Sensitive note',
            'content' => 'Use only while it remains available.',
        ]);
        $estimate = $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Use the selected note.',
            'memory_ids' => [$memory->id],
        ])->assertOk();

        $this->deleteJson("/api/v1/memories/{$memory->id}")->assertOk();
        $this->postJson('/api/v1/tasks', [
            'title' => 'Stale memory preview',
            'prompt' => 'Use the selected note.',
            'memory_ids' => [$memory->id],
            'memory_context_hash' => $estimate->json('memory_preview.hash'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('memory_ids');

        $this->assertDatabaseCount('tasks', 0);
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
            ->assertJsonPath('routing.agent_sequence.0', 'Research')
            ->assertJsonPath('task_brief.language', 'english')
            ->assertJsonPath('task_brief.intent', 'research')
            ->assertJsonPath('task_brief.original_prompt_preserved', true)
            ->assertJsonPath('matched_skills.0.name', 'research-brief');

        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Research and write a summary with an independent review.',
        ])->assertOk()
            ->assertJsonPath('routing.strategy', 'multi_agent')
            ->assertJsonPath('routing.agent_sequence', ['Orchestrator', 'Research', 'Writer', 'Reviewer', 'Skeptic']);
    }

    public function test_task_brief_complexity_is_passed_to_model_router_estimates(): void
    {
        $user = $this->userWithApprovedProvider();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Write a memo then then',
        ])->assertOk()
            ->assertJsonPath('task_brief.complexity.level', 'moderate')
            ->assertJsonPath('routing_cost_comparison.auto.agents.0.complexity', 'moderate');
    }

    public function test_single_agent_task_executes_and_returns_its_answer(): void
    {
        Queue::fake();
        Http::fake(function ($request) {
            $requestData = json_encode($request->data(), JSON_THROW_ON_ERROR);
            $isSkeptic = str_contains($requestData, 'Independently challenge');

            return Http::response([
                'choices' => [[
                    'message' => ['content' => $isSkeptic
                        ? json_encode([
                            'confidence' => 80,
                            'findings' => [],
                            'criteria' => [[
                                'criterion' => 'The response addresses the task and discloses material uncertainty.',
                                'status' => 'met',
                                'evidence' => 'The answer directly addresses the requested topic.',
                            ]],
                            'approved' => true,
                        ], JSON_THROW_ON_ERROR)
                        : 'Research answer.'],
                    'finish_reason' => 'stop',
                ]],
            ]);
        });
        Sanctum::actingAs($user = $this->userWithApprovedProvider());
        $settings = $user->llmSettings()->firstOrFail();
        $settings->update([
            'model_pricing' => [[
                'provider' => $settings->provider,
                'model' => $settings->model,
                'currency' => 'USD',
                'input_cost_per_million' => 1,
                'output_cost_per_million' => 2,
            ]],
        ]);

        $this->postJson('/api/v1/tasks', [
            'title' => 'Research task',
            'prompt' => 'Research a topic.',
        ])->assertAccepted();

        $task = Task::query()->firstOrFail();
        $agentRun = $task->agentRuns()->firstOrFail();
        $this->runTaskToCompletion($task);

        $task->refresh();
        $this->assertSame(TASK_STATUS_COMPLETED, $task->status);
        $this->assertSame('Research answer.', $task->result['answer']);
        $this->assertSame(2, $task->agentRuns()->count());
        $this->assertGreaterThan(0, $task->agentRuns()->firstOrFail()->token_usage['total_tokens']);
        $this->assertDatabaseHas('run_events', [
            'agent_run_id' => $agentRun->id,
            'event_type' => 'agent.started',
        ]);
        $completionEvent = $agentRun->runEvents()->where('event_type', 'agent.completed')->firstOrFail();
        $callEvent = $agentRun->runEvents()->where('event_type', 'agent.llm_call')->firstOrFail();
        $this->assertSame($settings->provider, $completionEvent->payload['provider']);
        $this->assertSame($settings->model, $completionEvent->payload['model']);
        $this->assertSame('USD', $completionEvent->payload['cost']['currency']);
        $this->assertGreaterThan(0, $callEvent->payload['usage']['input_tokens']);
        $this->assertSame('USD', $callEvent->payload['cost']['currency']);
        $this->assertGreaterThan(0, $completionEvent->payload['duration_ms']);
        $this->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('task.cost_summary.priced_call_count', 1)
            ->assertJsonPath('task.cost_summary.unpriced_call_count', 1)
            ->assertJsonPath('task.cost_summary.currency', 'USD');
        Http::assertSentCount(2);
    }

    public function test_single_and_multi_agent_routes_compare_the_same_test_set_results_and_costs(): void
    {
        Queue::fake();
        Http::fake(function ($request) {
            $requestData = json_encode($request->data(), JSON_THROW_ON_ERROR);
            $isSkeptic = str_contains($requestData, 'Independently challenge');

            return Http::response([
                'choices' => [[
                    'message' => ['content' => $isSkeptic
                        ? json_encode([
                            'confidence' => 80,
                            'findings' => [],
                            'criteria' => [[
                                'criterion' => 'The response addresses the task and discloses material uncertainty.',
                                'status' => 'met',
                                'evidence' => 'The answer directly addresses the requested topic.',
                            ]],
                            'approved' => true,
                        ], JSON_THROW_ON_ERROR)
                        : 'A comparable answer.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 3,
                    'completion_tokens' => 2,
                    'total_tokens' => 5,
                ],
            ]);
        });
        Sanctum::actingAs($user = $this->userWithApprovedProvider());
        $settings = $user->llmSettings()->firstOrFail();
        $settings->update([
            'model_pricing' => array_map(
                static fn (array $model): array => [
                    ...$model,
                    'currency' => 'USD',
                    'input_cost_per_million' => 1,
                    'output_cost_per_million' => 2,
                ],
                $settings->approved_models,
            ),
        ]);
        $testSet = [
            'research' => 'Research a topic.',
            'summary' => 'Summarize the supplied facts.',
        ];
        $tasks = [];

        foreach ($testSet as $caseName => $prompt) {
            foreach (['single_agent', 'multi_agent'] as $strategy) {
                $title = "{$caseName} {$strategy} comparison";
                $this->postJson('/api/v1/tasks', [
                    'title' => $title,
                    'prompt' => $prompt,
                    'routing_strategy' => $strategy,
                ])->assertAccepted()
                    ->assertJsonPath('task.routing.strategy', $strategy);
                $tasks[$caseName][$strategy] = Task::query()->where('title', $title)->firstOrFail();
            }
        }

        $agentRunService = app(AgentRunService::class);
        foreach ($tasks as $strategies) {
            foreach ($strategies as $task) {
                $this->runTaskToCompletion($task, $agentRunService);
            }
        }

        foreach ($tasks as $strategies) {
            $singleTask = $strategies['single_agent']->fresh();
            $multiTask = $strategies['multi_agent']->fresh();
            $singleTokenUsage = (int) $singleTask->agentRuns()
                ->get()
                ->sum(static fn (AgentRun $agentRun): int => (int) ($agentRun->token_usage['total_tokens'] ?? 0));
            $multiTokenUsage = (int) $multiTask->agentRuns()
                ->get()
                ->sum(static fn (AgentRun $agentRun): int => (int) ($agentRun->token_usage['total_tokens'] ?? 0));

            $this->assertSame('A comparable answer.', $singleTask->result['answer']);
            $this->assertSame($singleTask->result['answer'], $multiTask->result['answer']);
            $this->assertSame(2, $singleTask->agentRuns()->count());
            $this->assertSame(5, $multiTask->agentRuns()->count());
            $this->assertGreaterThan($singleTokenUsage, $multiTokenUsage);
            $this->assertSame(25, $multiTokenUsage);
            $this->assertSame('available', $singleTask->input['cost_estimate']['status']);
            $this->assertSame('available', $multiTask->input['cost_estimate']['status']);
            $this->assertGreaterThan(
                $singleTask->input['cost_estimate']['maximum_cost'],
                $multiTask->input['cost_estimate']['maximum_cost'],
            );
        }

        foreach ($tasks as $strategies) {
            foreach ($strategies as $task) {
                $runEvidence = $task->fresh()
                    ->agentRuns()
                    ->with(['messages', 'runEvents'])
                    ->get()
                    ->toArray();
                $this->assertStringNotContainsString(
                    'test-provider-key',
                    json_encode([$task->input, $runEvidence], JSON_THROW_ON_ERROR),
                );
            }
        }
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

    public function test_job_runs_the_routed_pipeline_and_completes_the_task(): void
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
        $modelIds = array_keys($catalog->provider($providerId)['models']);
        $modelId = $modelIds[0];
        $user = User::factory()->create();

        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $modelId,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $modelId],
                ['provider' => $providerId, 'model' => $modelIds[1]],
            ],
            'agent_models' => [],
        ]);
        foreach (['Orchestrator', 'Research', 'Writer', 'Reviewer', 'Skeptic'] as $agentName) {
            $user->apiKeys()->create([
                'agent_name' => $agentName,
                'provider' => $providerId,
                'api_key' => "test-provider-key-{$agentName}",
            ]);
        }

        return $user;
    }

    private function runTaskToCompletion(Task $task, ?AgentRunService $agentRunService = null): void
    {
        $agentRunService ??= app(AgentRunService::class);
        $maximumRuns = 12;

        while ($maximumRuns-- > 0) {
            $pendingRun = $task->agentRuns()
                ->where('status', AGENT_RUN_STATUS_PENDING)
                ->orderBy('id')
                ->first();

            if ($pendingRun === null) {
                break;
            }

            $agentRunService->run($pendingRun->id);
        }

        $this->assertSame(TASK_STATUS_COMPLETED, $task->fresh()->status);
    }
}
