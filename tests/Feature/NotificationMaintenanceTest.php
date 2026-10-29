<?php

namespace Tests\Feature;

use App\Jobs\DeliverUserNotificationJob;
use App\Models\PendingApproval;
use App\Models\User;
use App\Models\UserAccessPolicy;
use App\Models\UserNotification;
use App\Services\NotificationMaintenanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationMaintenanceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_policy_expiry_notice_is_queued_and_deduplicated(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $policy = UserAccessPolicy::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'agent_name' => 'Research',
            'tool_name' => 'web_search',
            'scope' => ACCESS_POLICY_SCOPE_ALWAYS,
            'maximum_uses' => 3,
            'status' => ACCESS_POLICY_STATUS_ACTIVE,
            'expires_at' => now()->addMinutes(30),
        ]);

        $maintenance = app(NotificationMaintenanceService::class);
        $this->assertSame(1, $maintenance->run());
        $this->assertSame(0, $maintenance->run());
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'type' => 'access_policy_expiring',
            'dedupe_key' => "policy-renewal:{$policy->id}:1h",
        ]);
        Queue::assertPushed(DeliverUserNotificationJob::class);
    }

    public function test_approval_reminders_escalate_and_do_not_create_after_decision(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->accessSettings()->create([
            'tenant_id' => $user->tenant_id,
            'access_mode' => ACCESS_MODE_USER,
            'agent_access_modes' => [],
            'notification_preferences' => [
                'tool_approval' => ['in_app' => true, 'channels' => ['in_app', 'email', 'telegram']],
            ],
        ]);
        $task = $user->tasks()->create([
            'title' => 'Reminder test',
            'description' => 'Wait for a safe tool approval.',
            'mode' => TASK_MODE_LIVE,
            'status' => TASK_STATUS_PENDING,
        ]);
        $run = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'input' => [],
            'status' => AGENT_RUN_STATUS_PENDING,
        ]);
        $approval = PendingApproval::query()->create([
            'task_id' => $task->id,
            'agent_run_id' => $run->id,
            'action' => 'tool:web_search',
            'requested_by' => $user->id,
            'payload' => ['tool_name' => 'web_search'],
            'status' => APPROVAL_STATUS_PENDING,
            'expires_at' => now()->addMinutes(20),
        ]);
        $initial = UserNotification::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'type' => 'tool_approval',
            'priority' => NOTIFICATION_PRIORITY_HIGH,
            'title' => 'Approval needed',
            'message' => 'Review in app.',
            'data' => ['approval_id' => $approval->id],
            'dedupe_key' => "reminder-test:{$approval->id}",
            'status' => NOTIFICATION_STATUS_UNREAD,
            'delivered_at' => now(),
            'next_reminder_at' => now()->addMinutes(5),
        ]);

        Carbon::setTestNow(now()->addMinutes(6));
        try {
            $maintenance = app(NotificationMaintenanceService::class);
            $this->assertSame(1, $maintenance->run());
            $reminder = UserNotification::query()
                ->where('dedupe_key', "tool-approval:{$approval->id}:reminder:1")
                ->firstOrFail();
            $this->assertSame(['email'], $reminder->data['delivery_channels']);
            $this->assertSame(1, $initial->fresh()->reminder_count);

            $approval->update(['status' => APPROVAL_STATUS_APPROVED]);
            Carbon::setTestNow(now()->addMinutes(6));
            $this->assertSame(0, $maintenance->run());
            $this->assertSame(2, UserNotification::query()
                ->where('type', 'tool_approval')
                ->where('data->approval_id', $approval->id)
                ->count());
        } finally {
            Carbon::setTestNow();
        }

        Queue::assertPushed(DeliverUserNotificationJob::class);
    }
}
