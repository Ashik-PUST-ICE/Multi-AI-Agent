<?php

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Jobs\RunAgentJob;
use App\Models\DeferredToolAction;
use App\Models\UserAccessSetting;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeferredToolActionService
{
    public function __construct(
        private readonly AgentActivityLogger $activityLogger,
        private readonly AgentRegistry $agentRegistry,
        private readonly ToolRegistry $tools,
        private readonly AccessPolicyEngine $accessPolicyEngine,
    ) {}

    public function cancel(int $actionId, int $userId): bool
    {
        return DB::transaction(function () use ($actionId, $userId): bool {
            $action = DeferredToolAction::query()
                ->whereKey($actionId)
                ->whereHas('approval.task', static fn ($query) => $query->where('user_id', $userId))
                ->lockForUpdate()
                ->firstOrFail();
            if ($action->status !== DEFERRED_TOOL_ACTION_PENDING) {
                return false;
            }

            $action->update([
                'status' => DEFERRED_TOOL_ACTION_CANCELLED,
                'cancelled_at' => now(),
            ]);
            $approval = $action->approval;
            if ($approval !== null && $approval->status === APPROVAL_STATUS_PENDING) {
                $approval->update([
                    'status' => APPROVAL_STATUS_REJECTED,
                    'decided_by' => $userId,
                    'decision_note' => 'Cancelled during the undo window.',
                    'decided_at' => now(),
                ]);
                $approval->agentRun?->update([
                    'status' => AGENT_RUN_STATUS_FAILED,
                    'error_message' => 'The owner cancelled this action during its undo window.',
                    'completed_at' => now(),
                ]);
                $approval->task?->update([
                    'status' => TASK_STATUS_FAILED,
                    'error_message' => 'The owner cancelled the pending tool action during its undo window.',
                    'completed_at' => now(),
                ]);
            }
            $this->activityLogger->recordEvent($approval?->agentRun, 'tool.undo_window_cancelled', [
                'deferred_action_id' => $action->id,
                'tool_name' => $action->tool_name,
            ]);

            return true;
        });
    }

    public function release(int $actionId): void
    {
        DB::transaction(function () use ($actionId): void {
            $action = DeferredToolAction::query()->lockForUpdate()->findOrFail($actionId);
            if ($action->status !== DEFERRED_TOOL_ACTION_PENDING) {
                return;
            }
            if ($action->scheduled_at->isFuture()) {
                return;
            }

            $approval = $action->approval;
            $task = $approval?->task;
            $agentRun = $approval?->agentRun;
            $policy = $action->policy;
            $accessSettings = $task?->user_id === null
                ? null
                : UserAccessSetting::query()->where('user_id', $task->user_id)->first();
            $agentAccessMode = (int) (($accessSettings?->agent_access_modes ?? [])[$action->agent_name]
                ?? $accessSettings?->access_mode
                ?? ACCESS_MODE_USER);
            try {
                $tool = $this->tools->get($action->tool_name);
                $inputMatches = $action->approval_signature === $this->tools->approvalSignature(
                    $action->tool_name,
                    $action->tool_input,
                );
            } catch (InvalidArgumentException) {
                $tool = null;
                $inputMatches = false;
            }
            $scopeValid = match ($policy?->scope) {
                ACCESS_POLICY_SCOPE_TASK => $policy->task_id === $task?->id,
                ACCESS_POLICY_SCOPE_DAY => $policy->created_at?->greaterThanOrEqualTo(now()->startOfDay()) === true,
                ACCESS_POLICY_SCOPE_WEEK => $policy->created_at?->greaterThanOrEqualTo(now()->startOfWeek()) === true,
                ACCESS_POLICY_SCOPE_ALWAYS => true,
                default => false,
            };
            $valid = $approval !== null
                && $approval->status === APPROVAL_STATUS_PENDING
                && $approval->expires_at !== null
                && $approval->expires_at->isFuture()
                && $task !== null
                && $task->status === TASK_STATUS_PENDING
                && $task->mode === TASK_MODE_LIVE
                && $agentRun !== null
                && $agentRun->status === AGENT_RUN_STATUS_PENDING
                && $policy !== null
                && $policy->status === ACCESS_POLICY_STATUS_ACTIVE
                && $policy->expires_at !== null
                && $policy->expires_at->isFuture()
                && $policy->uses_count < $policy->maximum_uses
                && $policy->agent_name === $action->agent_name
                && $policy->tool_name === $action->tool_name
                && $scopeValid
                && $inputMatches
                && ($tool !== null
                    && ($tool->minimumTrustLevel() < AGENT_TRUST_LEVEL_WRITE && ! $tool->requiresApproval()
                        || $this->accessPolicyEngine->matchesPolicyInput($policy, $action->tool_input)))
                && $agentAccessMode === ACCESS_MODE_AUTO
                && ! $this->accessPolicyEngine->isNeverAuto($action->tool_name)
                && $tool !== null
                && $tool->minimumTrustLevel() < AGENT_TRUST_LEVEL_SENSITIVE;

            if (! $valid) {
                $action->update(['status' => DEFERRED_TOOL_ACTION_EXPIRED]);
                if ($approval !== null && $approval->status === APPROVAL_STATUS_PENDING) {
                    $approval->update([
                        'status' => APPROVAL_STATUS_REJECTED,
                        'decision_note' => 'Deferred action became invalid before release.',
                        'decided_at' => now(),
                    ]);
                }

                return;
            }

            $policy->increment('uses_count');
            $approval->update([
                'status' => APPROVAL_STATUS_APPROVED,
                'decided_by' => $task->user_id,
                'decision_note' => 'Owner-authorized Auto policy released after undo window.',
                'decided_at' => now(),
            ]);
            $action->update([
                'status' => DEFERRED_TOOL_ACTION_RELEASED,
                'released_at' => now(),
            ]);
            $this->activityLogger->recordEvent($agentRun, 'tool.undo_window_released', [
                'deferred_action_id' => $action->id,
                'tool_name' => $action->tool_name,
            ]);

            RunAgentJob::dispatch(
                $agentRun->id,
                $this->agentRegistry->timeoutSeconds($agentRun->agent_name),
            )->afterCommit();
        });
    }
}
