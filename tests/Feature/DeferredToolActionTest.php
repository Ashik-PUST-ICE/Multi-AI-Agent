<?php

namespace Tests\Feature;

use App\Jobs\RunAgentJob;
use App\Models\DeferredToolAction;
use App\Models\PendingApproval;
use App\Models\Task;
use App\Models\User;
use App\Models\UserAccessPolicy;
use App\Models\UserAccessSetting;
use App\Services\DeferredToolActionService;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeferredToolActionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_owner_can_cancel_deferred_action_and_it_cannot_later_be_released(): void
    {
        Queue::fake();
        [$user, $task, $approval, $action] = $this->makeDeferredAction();

        $service = app(DeferredToolActionService::class);
        $this->assertTrue($service->cancel($action->id, $user->id));
        $service->release($action->id);

        $this->assertSame(DEFERRED_TOOL_ACTION_CANCELLED, $action->fresh()->status);
        $this->assertSame(APPROVAL_STATUS_REJECTED, $approval->fresh()->status);
        $this->assertSame(TASK_STATUS_FAILED, $task->fresh()->status);
        Queue::assertNotPushed(RunAgentJob::class);
    }

    public function test_deferred_release_revalidates_current_mode_policy_scope_and_exact_input(): void
    {
        Queue::fake();
        [, , $approval, $action, $policy] = $this->makeDeferredAction();
        $policy->update(['input_constraints' => ['query' => 'different input']]);
        $action->update(['scheduled_at' => now()->subSecond()]);

        app(DeferredToolActionService::class)->release($action->id);

        $this->assertSame(DEFERRED_TOOL_ACTION_EXPIRED, $action->fresh()->status);
        $this->assertSame(APPROVAL_STATUS_REJECTED, $approval->fresh()->status);
        Queue::assertNotPushed(RunAgentJob::class);
    }

    public function test_valid_deferred_action_releases_only_after_undo_window(): void
    {
        Queue::fake();
        [, , $approval, $action, $policy] = $this->makeDeferredAction();
        $action->update(['scheduled_at' => now()->subSecond()]);

        app(DeferredToolActionService::class)->release($action->id);

        $this->assertSame(DEFERRED_TOOL_ACTION_RELEASED, $action->fresh()->status);
        $this->assertSame(APPROVAL_STATUS_APPROVED, $approval->fresh()->status);
        $this->assertSame(1, $policy->fresh()->uses_count);
        Queue::assertPushed(RunAgentJob::class);
    }

    /**
     * @return array{User, Task, PendingApproval, DeferredToolAction, UserAccessPolicy}
     */
    private function makeDeferredAction(): array
    {
        $user = User::factory()->create();
        UserAccessSetting::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'access_mode' => ACCESS_MODE_AUTO,
            'agent_access_modes' => ['Research' => ACCESS_MODE_AUTO],
            'notification_preferences' => ['tool_approval' => ['channels' => ['in_app']]],
        ]);
        $task = $user->tasks()->create([
            'title' => 'Deferred tool action',
            'description' => 'Run one bounded search.',
            'mode' => TASK_MODE_LIVE,
            'status' => TASK_STATUS_PENDING,
        ]);
        $run = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'input' => [],
            'status' => AGENT_RUN_STATUS_PENDING,
        ]);
        $input = ['query' => 'Laravel'];
        $policy = UserAccessPolicy::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'task_id' => $task->id,
            'agent_name' => 'Research',
            'tool_name' => 'web_search',
            'input_constraints' => $input,
            'scope' => ACCESS_POLICY_SCOPE_TASK,
            'maximum_uses' => 1,
            'undo_window_seconds' => 10,
            'status' => ACCESS_POLICY_STATUS_ACTIVE,
            'expires_at' => now()->addHour(),
        ]);
        $approval = PendingApproval::query()->create([
            'task_id' => $task->id,
            'agent_run_id' => $run->id,
            'action' => 'tool_call',
            'requested_by' => 'Research',
            'payload' => ['tool_name' => 'web_search', 'tool_input' => $input],
            'status' => APPROVAL_STATUS_PENDING,
            'expires_at' => now()->addMinutes(10),
        ]);
        $action = DeferredToolAction::query()->create([
            'pending_approval_id' => $approval->id,
            'policy_id' => $policy->id,
            'task_id' => $task->id,
            'agent_run_id' => $run->id,
            'agent_name' => 'Research',
            'tool_name' => 'web_search',
            'tool_input' => $input,
            'approval_signature' => app(ToolRegistry::class)->approvalSignature('web_search', $input),
            'scheduled_at' => now()->addSeconds(10),
            'status' => DEFERRED_TOOL_ACTION_PENDING,
        ]);

        return [$user, $task, $approval, $action, $policy];
    }
}
