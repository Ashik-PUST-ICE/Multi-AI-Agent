<?php

namespace App\Services;

use App\Jobs\DeliverUserNotificationJob;
use App\Models\PendingApproval;
use App\Models\User;
use App\Models\UserAccessPolicy;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

class NotificationMaintenanceService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function run(): int
    {
        $created = 0;
        $expiredOwners = PendingApproval::query()
            ->where('status', APPROVAL_STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('task')
            ->get()
            ->pluck('task.user_id')
            ->filter()
            ->unique();

        foreach ($expiredOwners as $userId) {
            $user = User::query()->find($userId);
            if ($user !== null) {
                app(ToolApprovalService::class)->pendingFor($user);
            }
        }

        $dueNotifications = UserNotification::query()
            ->where('type', 'tool_approval')
            ->where('reminder_count', '<', 3)
            ->whereNotNull('next_reminder_at')
            ->where('next_reminder_at', '<=', now())
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($dueNotifications as $notification) {
            $created += (int) DB::transaction(function () use ($notification): bool {
                $locked = UserNotification::query()->lockForUpdate()->find($notification->id);
                if ($locked === null
                    || $locked->next_reminder_at === null
                    || $locked->next_reminder_at->isFuture()
                    || $locked->reminder_count >= 3) {
                    return false;
                }

                $approvalId = $locked->data['approval_id'] ?? null;
                $approval = is_numeric($approvalId)
                    ? PendingApproval::query()->with('task.user')->find((int) $approvalId)
                    : null;
                if ($approval === null
                    || $approval->status !== APPROVAL_STATUS_PENDING
                    || ($approval->expires_at !== null && $approval->expires_at->isPast())) {
                    $locked->update(['next_reminder_at' => null]);

                    return false;
                }

                $number = $locked->reminder_count + 1;
                $this->notifications->approvalReminder($approval, $number);
                $locked->update([
                    'reminder_count' => $number,
                    'next_reminder_at' => $number >= 3 ? null : now()->addMinutes(5),
                ]);

                return true;
            });
        }

        $policies = UserAccessPolicy::query()
            ->where('status', ACCESS_POLICY_STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDay())
            ->with('task')
            ->limit(200)
            ->get();
        foreach ($policies as $policy) {
            $hoursUntilExpiry = now()->diffInMinutes($policy->expires_at, false);
            $reminderType = $hoursUntilExpiry <= 60 ? '1h' : '24h';
            $dedupeKey = "policy-renewal:{$policy->id}:{$reminderType}";
            $exists = UserNotification::query()->where('dedupe_key', $dedupeKey)->exists();
            if ($exists) {
                continue;
            }

            $notification = UserNotification::query()->create([
                'user_id' => $policy->user_id,
                'tenant_id' => $policy->tenant_id,
                'type' => 'access_policy_expiring',
                'priority' => NOTIFICATION_PRIORITY_NORMAL,
                'title' => 'Auto access policy is expiring',
                'message' => "Your {$policy->agent_name} policy for {$policy->tool_name} expires soon. It will not renew automatically.",
                'data' => ['policy_id' => $policy->id, 'scope' => $reminderType],
                'dedupe_key' => $dedupeKey,
                'status' => NOTIFICATION_STATUS_UNREAD,
                'delivered_at' => now(),
            ]);
            DeliverUserNotificationJob::dispatch($notification->id)->afterCommit();
            $policy->update(['last_reminder_at' => now()]);
            $created++;
        }

        return $created;
    }
}
