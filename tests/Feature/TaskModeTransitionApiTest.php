<?php

namespace Tests\Feature;

use App\Jobs\RunAgentJob;
use App\Llm\ProviderCatalog;
use App\Models\ModeTransition;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskModeTransitionApiTest extends TestCase
{
    use DatabaseMigrations;

    public function test_live_transition_is_fail_closed_and_logs_the_user_request(): void
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'Completed sandbox task',
            'description' => 'A sandboxed task.',
            'input' => [
                'prompt' => 'A sandboxed task.',
                'routing' => [
                    'strategy' => 'single_agent',
                    'agent_sequence' => ['Writer'],
                ],
            ],
            'mode' => TASK_MODE_SANDBOX,
            'status' => TASK_STATUS_COMPLETED,
            'result' => ['answer' => 'Safe simulated result.'],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/tasks/{$task->id}/transition-to-live", [
            'confirm_live_transition' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('transition.status', 'blocked')
            ->assertJsonPath('transition.blocked_requirements.0', 'evaluation_must_pass')
            ->assertJsonPath('transition.blocked_requirements.1', 'skeptic_report_must_be_good');

        $transition = ModeTransition::query()->firstOrFail();
        $task->refresh();
        $this->assertSame(MODE_TRANSITION_STATUS_BLOCKED, $transition->status);
        $this->assertTrue($transition->user_confirmed);
        $this->assertNull($transition->resulting_task_id);
        $this->assertSame(TASK_MODE_SANDBOX, $task->mode);
        $this->assertSame(1, Task::query()->count());
        $this->assertDatabaseHas('run_events', [
            'task_id' => $task->id,
            'event_type' => 'task.live_transition_blocked',
            'mode' => TASK_MODE_SANDBOX,
        ]);
    }

    public function test_live_transition_requires_explicit_confirmation_and_task_ownership(): void
    {
        $owner = User::factory()->create();
        $task = $owner->tasks()->create([
            'title' => 'Private sandbox task',
            'mode' => TASK_MODE_SANDBOX,
            'status' => TASK_STATUS_COMPLETED,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/tasks/{$task->id}/transition-to-live", [
            'confirm_live_transition' => true,
        ])->assertNotFound();

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/tasks/{$task->id}/transition-to-live", [
            'confirm_live_transition' => false,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('mode_transitions', 0);
    }

    public function test_live_transition_creates_a_separate_live_task_after_all_trusted_gates_pass(): void
    {
        Queue::fake();
        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $modelId = array_key_first($catalog->provider($providerId)['models']);
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $modelId,
            'approved_models' => [['provider' => $providerId, 'model' => $modelId]],
            'agent_models' => [],
        ]);
        $user->apiKeys()->create([
            'provider' => $providerId,
            'api_key' => 'transition-test-provider-key',
        ]);
        $sourceTask = $user->tasks()->create([
            'title' => 'Reviewed sandbox task',
            'description' => 'A reviewed request.',
            'input' => [
                'prompt' => 'A reviewed request.',
                'routing' => [
                    'strategy' => 'single_agent',
                    'agent_sequence' => ['Writer'],
                ],
            ],
            'mode' => TASK_MODE_SANDBOX,
            'status' => TASK_STATUS_COMPLETED,
        ]);
        $sourceTask->agentRuns()->create([
            'agent_name' => 'Writer',
            'mode' => TASK_MODE_SANDBOX,
            'status' => AGENT_RUN_STATUS_COMPLETED,
            'input' => ['prompt' => 'A reviewed request.', 'context' => []],
            'output' => ['text' => 'Reviewed sandbox output.'],
        ]);
        foreach (['evaluation.passed', 'skeptic.approved'] as $eventType) {
            $sourceTask->runEvents()->create([
                'event_type' => $eventType,
                'mode' => TASK_MODE_SANDBOX,
                'sequence' => $sourceTask->runEvents()->count() + 1,
                'payload' => ['status' => 'passed'],
            ]);
        }
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tasks/{$sourceTask->id}/transition-to-live", [
            'confirm_live_transition' => true,
        ])->assertAccepted()
            ->assertJsonPath('transition.status', MODE_TRANSITION_STATUS_APPLIED)
            ->assertJsonPath('task.mode', TASK_MODE_LIVE)
            ->assertJsonPath('task.status', TASK_STATUS_PENDING);

        $liveTaskId = $response->json('task.id');
        $liveTask = Task::query()->findOrFail($liveTaskId);
        $transition = ModeTransition::query()->firstOrFail();

        $this->assertSame(TASK_MODE_SANDBOX, $sourceTask->fresh()->mode);
        $this->assertSame(TASK_MODE_LIVE, $liveTask->mode);
        $this->assertSame($liveTask->id, $transition->resulting_task_id);
        $this->assertSame(TASK_MODE_LIVE, $liveTask->agentRuns()->firstOrFail()->mode);
        $this->assertDatabaseHas('run_events', [
            'task_id' => $liveTask->id,
            'event_type' => 'task.mode_transitioned',
            'mode' => TASK_MODE_LIVE,
        ]);
        Queue::assertPushed(RunAgentJob::class);
    }

    public function test_live_task_replay_is_owner_scoped_read_only_and_marked_sandbox(): void
    {
        $owner = User::factory()->create();
        $task = $owner->tasks()->create([
            'title' => 'Live task',
            'mode' => TASK_MODE_LIVE,
            'status' => TASK_STATUS_COMPLETED,
        ]);
        $task->runEvents()->create([
            'event_type' => 'agent.completed',
            'mode' => TASK_MODE_LIVE,
            'sequence' => 1,
            'payload' => ['result' => 'Redacted event data'],
        ]);
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/tasks/{$task->id}/replay")
            ->assertOk()
            ->assertJsonPath('replay_mode', TASK_MODE_SANDBOX)
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('events.0.source_mode', TASK_MODE_LIVE)
            ->assertJsonPath('events.0.mode', TASK_MODE_SANDBOX);

        $this->assertSame(TASK_MODE_LIVE, $task->fresh()->mode);
        $this->assertSame(1, $task->runEvents()->count());

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/tasks/{$task->id}/replay")->assertNotFound();
    }
}
