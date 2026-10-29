<?php

namespace Tests\Feature;

use App\Jobs\RunAgentJob;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentActivityLogger;
use App\Services\OrchestratorService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlightRecorderApiTest extends TestCase
{
    use DatabaseMigrations;

    public function test_owner_can_export_safe_open_telemetry_trace_spans(): void
    {
        $owner = User::factory()->create();
        $task = $owner->tasks()->create([
            'title' => 'Trace task',
            'input' => ['prompt' => 'Do not export this private prompt.'],
        ]);
        $run = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'status' => AGENT_RUN_STATUS_COMPLETED,
            'input' => ['prompt' => 'Private input.'],
            'started_at' => now()->subSeconds(3),
            'completed_at' => now(),
        ]);
        $task->runEvents()->create([
            'agent_run_id' => $run->id,
            'event_type' => 'agent.llm_call',
            'mode' => TASK_MODE_SANDBOX,
            'sequence' => 1,
            'payload' => [
                'provider' => 'user-configured-provider',
                'model' => 'approved-model',
                'duration_ms' => 25,
                'usage' => ['input_tokens' => 12, 'output_tokens' => 8, 'total_tokens' => 20],
                'private_prompt' => 'Never expose this prompt.',
            ],
            'occurred_at' => now(),
        ]);
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/tasks/{$task->id}/trace")->assertOk();
        $spans = $response->json('resourceSpans.0.scopeSpans.0.spans');

        $this->assertCount(3, $spans);
        $this->assertSame($task->trace_id, $spans[0]['traceId']);
        $this->assertSame($task->trace_span_id, $spans[0]['spanId']);
        $this->assertSame($task->trace_span_id, $spans[1]['parentSpanId']);
        $this->assertSame($run->trace_span_id, $spans[2]['parentSpanId']);
        $this->assertSame($task->trace_id, $spans[2]['traceId']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $spans[2]['traceId']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $spans[2]['spanId']);
        $inputTokens = collect($spans[2]['attributes'])->firstWhere('key', 'llm.input_tokens');
        $this->assertSame('12', $inputTokens['value']['intValue']);
        $response->assertDontSee('Do not export this private prompt.')
            ->assertDontSee('Never expose this prompt.')
            ->assertJsonPath('resourceSpans.0.scopeSpans.0.spans.2.name', 'agent.llm_call');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/tasks/{$task->id}/trace")->assertNotFound();
    }

    public function test_observability_summary_is_owner_scoped_and_exposes_limited_review_signals(): void
    {
        $owner = User::factory()->create();
        $task = $owner->tasks()->create(['title' => 'Observed task']);
        for ($sequence = 1; $sequence <= 5; $sequence++) {
            $task->runEvents()->create([
                'event_type' => 'skeptic.assessment',
                'sequence' => $sequence,
                'payload' => ['approved' => true, 'findings' => []],
                'occurred_at' => now(),
            ]);
        }
        $task->runEvents()->create([
            'event_type' => 'agent.failed',
            'sequence' => 6,
            'payload' => ['error' => 'The selected provider connection failed.'],
            'occurred_at' => now(),
        ]);
        $task->runEvents()->create([
            'event_type' => 'tool.failed',
            'sequence' => 7,
            'payload' => ['failure_category' => 'tool'],
            'occurred_at' => now(),
        ]);
        $task->runEvents()->create([
            'event_type' => 'observability.silent_failure',
            'sequence' => 8,
            'payload' => ['failure_category' => 'validation'],
            'occurred_at' => now(),
        ]);

        $foreignTask = User::factory()->create()->tasks()->create(['title' => 'Not visible']);
        $foreignTask->runEvents()->create([
            'event_type' => 'agent.failed',
            'sequence' => 1,
            'payload' => ['error' => 'Provider connection failed.'],
            'occurred_at' => now(),
        ]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/observability/summary')
            ->assertOk()
            ->assertJsonPath('period_days', 30)
            ->assertJsonPath('failure_categories.provider', 1)
            ->assertJsonPath('failure_categories.tool', 1)
            ->assertJsonPath('failure_categories.validation', 1)
            ->assertJsonPath('warnings.silent_failures', 1)
            ->assertJsonPath('skeptic_review_signal.sample_size', 5)
            ->assertJsonPath('skeptic_review_signal.warning', true);
    }

    public function test_empty_and_repeated_agent_outputs_fail_instead_of_completing_tasks(): void
    {
        $owner = User::factory()->create();
        $emptyTask = $owner->tasks()->create(['title' => 'Empty output task']);
        $emptyRun = $emptyTask->agentRuns()->create([
            'agent_name' => 'Research',
            'status' => AGENT_RUN_STATUS_RUNNING,
            'started_at' => now(),
        ]);

        app(OrchestratorService::class)->completeAgentRun($emptyRun->id, ['text' => '  ']);

        $this->assertSame(TASK_STATUS_FAILED, $emptyTask->fresh()->status);
        $this->assertSame(AGENT_RUN_STATUS_FAILED, $emptyRun->fresh()->status);
        $this->assertDatabaseHas('run_events', [
            'task_id' => $emptyTask->id,
            'event_type' => 'observability.silent_failure',
        ]);

        $stagnantTask = $owner->tasks()->create(['title' => 'Repeated output task']);
        $stagnantTask->agentRuns()->create([
            'agent_name' => 'Research',
            'status' => AGENT_RUN_STATUS_COMPLETED,
            'input' => ['prompt' => 'Same request'],
            'output' => ['text' => 'Same result'],
        ]);
        $repeatedRun = $stagnantTask->agentRuns()->create([
            'agent_name' => 'Research',
            'status' => AGENT_RUN_STATUS_RUNNING,
            'input' => ['prompt' => 'Same request'],
            'started_at' => now(),
        ]);

        app(OrchestratorService::class)->completeAgentRun($repeatedRun->id, ['text' => " Same   result\n"]);

        $this->assertSame(TASK_STATUS_FAILED, $stagnantTask->fresh()->status);
        $this->assertSame(AGENT_RUN_STATUS_FAILED, $repeatedRun->fresh()->status);
        $this->assertDatabaseHas('run_events', [
            'task_id' => $stagnantTask->id,
            'event_type' => 'observability.stagnation_detected',
        ]);
    }

    public function test_task_replay_includes_redacted_messages_and_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $task = $owner->tasks()->create([
            'title' => 'Recorded task',
            'input' => ['prompt' => 'Private prompt.'],
        ]);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'input' => ['prompt' => 'Private prompt.'],
        ]);
        app(AgentActivityLogger::class)->recordMessage($agentRun, 'user', 'Private prompt.');

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/tasks/{$task->id}/replay")
            ->assertOk()
            ->assertJsonPath('events.0.event_type', 'agent.message')
            ->assertJsonPath('events.0.message.content', 'Private prompt.')
            ->assertJsonPath('events.0.mode', TASK_MODE_SANDBOX);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/tasks/{$task->id}/replay")->assertNotFound();
    }

    public function test_owner_can_create_revoke_and_share_a_limited_read_only_replay(): void
    {
        $owner = User::factory()->create();
        $task = $owner->tasks()->create([
            'title' => 'Shareable task',
            'input' => ['prompt' => 'Do not share this prompt.'],
        ]);
        $task->runEvents()->create([
            'event_type' => 'agent.started',
            'mode' => TASK_MODE_SANDBOX,
            'sequence' => 1,
            'payload' => [
                'agent' => 'Research',
                'provider' => 'custom',
                'model' => 'approved-model',
                'routing' => ['reason' => 'The user selected this approved model.'],
                'private_prompt' => 'Do not expose this field.',
            ],
            'occurred_at' => now(),
        ]);
        Sanctum::actingAs($owner);

        $createResponse = $this->postJson("/api/v1/tasks/{$task->id}/replay-shares", [
            'expires_in_hours' => 24,
        ])->assertCreated()
            ->assertJsonStructure(['share' => ['id', 'url', 'expires_at']]);

        $shareUrl = $createResponse->json('share.url');
        $token = basename(parse_url($shareUrl, PHP_URL_PATH));
        $shareId = $createResponse->json('share.id');

        $this->getJson("/api/v1/task-replays/{$token}")
            ->assertOk()
            ->assertJsonPath('task.title', 'Shareable task')
            ->assertJsonPath('events.0.details.provider', 'custom')
            ->assertJsonMissingPath('events.0.details.private_prompt')
            ->assertDontSee('Do not share this prompt.');

        $this->deleteJson("/api/v1/tasks/{$task->id}/replay-shares/{$shareId}")
            ->assertOk();
        $this->getJson("/api/v1/task-replays/{$token}")->assertNotFound();
    }

    public function test_share_links_expire_and_cannot_be_created_for_another_users_task(): void
    {
        $owner = User::factory()->create();
        $task = $owner->tasks()->create([
            'title' => 'Private task',
            'input' => ['prompt' => 'Private prompt.'],
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/tasks/{$task->id}/replay-shares")
            ->assertNotFound();

        Sanctum::actingAs($owner);
        $shareResponse = $this->postJson("/api/v1/tasks/{$task->id}/replay-shares", [
            'expires_in_hours' => 1,
        ])
            ->assertCreated();
        $token = basename(parse_url($shareResponse->json('share.url'), PHP_URL_PATH));

        $this->travel(2)->hours();
        $this->getJson("/api/v1/task-replays/{$token}")->assertNotFound();
    }

    public function test_completed_step_restarts_as_a_new_sandbox_task_only(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $task = $owner->tasks()->create([
            'title' => 'Original live task',
            'mode' => TASK_MODE_LIVE,
            'input' => ['prompt' => 'Run the saved step.'],
        ]);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'mode' => TASK_MODE_LIVE,
            'status' => AGENT_RUN_STATUS_COMPLETED,
            'input' => ['prompt' => 'Run the saved step.', 'context' => ['source' => 'recorded context']],
        ]);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/tasks/{$task->id}/replay/{$agentRun->id}/resume", [])
            ->assertAccepted()
            ->assertJsonPath('task.mode', TASK_MODE_SANDBOX)
            ->assertJsonPath('task.routing.agent_sequence', ['Research']);

        $replayTaskId = $response->json('task.id');
        $replayTask = Task::query()->findOrFail($replayTaskId);
        $replayRun = $replayTask->agentRuns()->firstOrFail();
        $this->assertSame(TASK_MODE_SANDBOX, $replayRun->mode);
        $this->assertSame($replayTask->id, $replayRun->input['task_id']);
        $this->assertSame('recorded context', $replayRun->input['payload']['context']['source']);
        $this->assertDatabaseHas('run_events', [
            'task_id' => $replayTask->id,
            'event_type' => 'task.replay_started',
        ]);
        Queue::assertPushed(RunAgentJob::class);
    }

    public function test_pending_step_cannot_be_restarted(): void
    {
        $owner = User::factory()->create();
        $task = $owner->tasks()->create([
            'title' => 'Pending task',
            'input' => ['prompt' => 'Pending prompt.'],
        ]);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'input' => ['prompt' => 'Pending prompt.', 'context' => []],
            'status' => AGENT_RUN_STATUS_PENDING,
        ]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/tasks/{$task->id}/replay/{$agentRun->id}/resume", [])
            ->assertConflict()
            ->assertJsonPath('message', 'Only a completed or failed agent step can be restarted.');
        $this->assertDatabaseCount('tasks', 1);
    }
}
