<?php

namespace App\Services;

use App\Jobs\DeliverUserNotificationJob;
use App\Models\NotificationResponseToken;
use App\Models\PendingApproval;
use App\Models\Task;
use App\Models\User;
use App\Models\UserAccessSetting;
use App\Models\UserNotification;
use App\Tools\ToolRegistry;

class NotificationService
{
    public function toolApprovalRequested(User $user, int $approvalId, Task $task): ?UserNotification
    {
        $settings = UserAccessSetting::query()->firstWhere('user_id', $user->id);
        $channels = $settings?->notification_preferences['tool_approval']['channels'] ?? ['in_app'];

        $approval = PendingApproval::query()->findOrFail($approvalId);
        $isDeferred = $approval->deferredAction?->status === DEFERRED_TOOL_ACTION_PENDING;
        $responseLinks = ! $isDeferred && count(array_diff($channels, ['in_app'])) > 0
            ? $this->createLowRiskResponseLinks($user, $approval)
            : null;
        $notification = UserNotification::query()->updateOrCreate(
            ['dedupe_key' => "tool-approval:{$approvalId}"],
            [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'type' => 'tool_approval',
                'priority' => NOTIFICATION_PRIORITY_HIGH,
                'title' => 'Tool action needs your approval',
                'message' => $isDeferred
                    ? 'This action is waiting in its configured undo window. Cancel it before the deadline to stop it.'
                    : 'Review the pending action in your Approvals page. It will not run without your response.',
                'data' => ['task_id' => $task->id, 'approval_id' => $approvalId],
                'external_response_links' => $responseLinks,
                'status' => NOTIFICATION_STATUS_UNREAD,
                'delivered_at' => now(),
                'next_reminder_at' => now()->addMinutes(5),
            ],
        );
        DeliverUserNotificationJob::dispatch($notification->id)->afterCommit();

        return $notification;
    }

    public function accessAutoReverted(User $user, Task $task, string $agentName, string $toolName): UserNotification
    {
        $notification = UserNotification::query()->updateOrCreate(
            ['dedupe_key' => "access-auto-reverted:{$task->id}:{$agentName}:{$toolName}"],
            [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'type' => 'access_mode_reverted',
                'priority' => NOTIFICATION_PRIORITY_HIGH,
                'title' => 'Auto access paused',
                'message' => 'Access returned to User mode because a safe, active policy did not cover this tool call.',
                'data' => ['task_id' => $task->id],
                'status' => NOTIFICATION_STATUS_UNREAD,
                'delivered_at' => now(),
            ],
        );
        DeliverUserNotificationJob::dispatch($notification->id)->afterCommit();

        return $notification;
    }

    public function approvalReminder(PendingApproval $approval, int $reminderNumber): ?UserNotification
    {
        $approval->loadMissing(['task.user']);
        $user = $approval->task?->user;
        if ($user === null) {
            return null;
        }

        $settings = UserAccessSetting::query()->firstWhere('user_id', $user->id);
        $channels = $settings?->notification_preferences['tool_approval']['channels'] ?? ['in_app'];

        $toolName = $approval->payload['tool_name'] ?? null;
        $isDeferred = $approval->deferredAction?->status === DEFERRED_TOOL_ACTION_PENDING;
        $responseLinks = ! $isDeferred && is_string($toolName) && count(array_diff($channels, ['in_app'])) > 0
            ? $this->createLowRiskResponseLinks($user, $approval)
            : null;
        $escalationChannels = array_values(array_filter($channels, static fn (string $channel): bool => $channel !== 'in_app'));
        $channel = $escalationChannels === []
            ? (in_array('in_app', $channels, true) ? 'in_app' : null)
            : $escalationChannels[min($reminderNumber - 1, count($escalationChannels) - 1)];
        $notification = UserNotification::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'type' => 'tool_approval',
            'priority' => NOTIFICATION_PRIORITY_HIGH,
            'title' => 'Reminder: tool action still needs your decision',
            'message' => 'No response was received. The pending action remains paused and will expire without your consent.',
            'data' => [
                'task_id' => $approval->task_id,
                'approval_id' => $approval->id,
                'delivery_channels' => $channel === null ? [] : [$channel],
            ],
            'external_response_links' => $responseLinks,
            'dedupe_key' => "tool-approval:{$approval->id}:reminder:{$reminderNumber}",
            'status' => NOTIFICATION_STATUS_UNREAD,
            'delivered_at' => now(),
        ]);
        DeliverUserNotificationJob::dispatch($notification->id)->afterCommit();

        return $notification;
    }

    /**
     * @return array<string, string>|null
     */
    private function createLowRiskResponseLinks(User $user, PendingApproval $approval): ?array
    {
        $toolName = $approval->payload['tool_name'] ?? null;
        if (! is_string($toolName)) {
            return null;
        }

        try {
            $tool = app(ToolRegistry::class)->get($toolName);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ($tool->minimumTrustLevel() >= AGENT_TRUST_LEVEL_WRITE
            || $tool->requiresApproval()
            || app(AccessPolicyEngine::class)->isNeverAuto($toolName)) {
            return null;
        }

        $links = [];
        foreach (['approve', 'reject'] as $decision) {
            $token = bin2hex(random_bytes(32));
            NotificationResponseToken::query()->create([
                'pending_approval_id' => $approval->id,
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $token),
                'decision' => $decision,
                'expires_at' => $approval->expires_at ?? now()->addMinutes(15),
            ]);
            $links[$decision] = url("/api/v1/notification-responses/{$token}/confirm");
        }

        return $links;
    }
}
