<?php

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Jobs\RunAgentJob;
use App\Models\AgentRun;
use App\Models\PendingApproval;
use App\Models\Task;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ToolApprovalService
{
    use ResponseTrait;

    public function __construct(
        private readonly AgentActivityLogger $activityLogger,
        private readonly AgentRegistry $agentRegistry,
    ) {}

    public function pendingFor(User $user): JsonResponse
    {
        $this->expirePendingApprovals($user);

        $approvals = PendingApproval::query()
            ->where('status', APPROVAL_STATUS_PENDING)
            ->whereHas('task', static function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->orderBy('created_at')
            ->get()
            ->map(static fn (PendingApproval $approval): array => [
                'id' => $approval->id,
                'task_id' => $approval->task_id,
                'agent_run_id' => $approval->agent_run_id,
                'action' => $approval->action,
                'payload' => $approval->payload,
                'deferred_action' => $approval->deferredAction === null ? null : [
                    'id' => $approval->deferredAction->id,
                    'scheduled_at' => $approval->deferredAction->scheduled_at,
                    'status' => $approval->deferredAction->status,
                ],
                'expires_at' => $approval->expires_at,
                'created_at' => $approval->created_at,
            ])
            ->all();

        return $this->success(['approvals' => $approvals]);
    }

    public function decide(User $user, int $approvalId, bool $isApproved, ?string $decisionNote): JsonResponse
    {
        $decision = DB::transaction(function () use ($user, $approvalId, $isApproved, $decisionNote): array {
            $approval = $this->approvalForUpdate($user, $approvalId);
            Gate::forUser($user)->authorize('decide', $approval);

            if ($approval->status !== APPROVAL_STATUS_PENDING) {
                throw new HttpException(409, 'This approval has already been decided.');
            }
            $deferredAction = $approval->deferredAction;
            if ($deferredAction?->status === DEFERRED_TOOL_ACTION_PENDING) {
                if ($isApproved) {
                    throw new HttpException(409, 'This action is in its undo window; wait for release or cancel it.');
                }
                app(DeferredToolActionService::class)->cancel($deferredAction->id, $user->id);

                return [
                    'expired' => false,
                    'approval_id' => $approval->id,
                    'status' => APPROVAL_STATUS_REJECTED,
                ];
            }

            if ($approval->expires_at !== null && $approval->expires_at->isPast()) {
                $this->expire($approval);

                return ['expired' => true, 'approval_id' => $approval->id];
            }

            $approval->update([
                'status' => $isApproved ? APPROVAL_STATUS_APPROVED : APPROVAL_STATUS_REJECTED,
                'decided_by' => $user->id,
                'decision_note' => $decisionNote,
                'decided_at' => now(),
            ]);

            $agentRun = $approval->agentRun;
            $task = $approval->task;

            if ($agentRun === null || $task === null) {
                throw new HttpException(409, 'The approval is no longer linked to an active task.');
            }

            if ($isApproved) {
                $this->activityLogger->recordEvent($agentRun, 'tool.approval_granted', [
                    'approval_id' => $approval->id,
                    'tool_name' => $approval->payload['tool_name'] ?? null,
                ]);
                RunAgentJob::dispatch(
                    $agentRun->id,
                    $this->agentRegistry->timeoutSeconds($agentRun->agent_name),
                )->afterCommit();

                return [
                    'expired' => false,
                    'approval_id' => $approval->id,
                    'status' => APPROVAL_STATUS_APPROVED,
                ];
            }

            $this->failApprovedTask($agentRun, $task, 'The user rejected the requested tool action.');
            $this->activityLogger->recordEvent($agentRun, 'tool.approval_rejected', [
                'approval_id' => $approval->id,
                'tool_name' => $approval->payload['tool_name'] ?? null,
            ]);

            return [
                'expired' => false,
                'approval_id' => $approval->id,
                'status' => APPROVAL_STATUS_REJECTED,
            ];
        });

        if ($decision['expired']) {
            throw new HttpException(410, 'This approval has expired.');
        }

        $message = $isApproved ? UPDATED_SUCCESSFULLY : DELETED_SUCCESSFULLY;

        return $this->success([
            'approval_id' => $decision['approval_id'],
            'status' => $decision['status'],
        ], $message);
    }

    /**
     * @param  list<int>  $approvalIds
     */
    public function decideBatch(User $user, array $approvalIds, bool $isApproved, ?string $decisionNote): JsonResponse
    {
        $result = DB::transaction(function () use ($user, $approvalIds, $isApproved, $decisionNote): array {
            $approvals = PendingApproval::query()
                ->whereIn('id', $approvalIds)
                ->whereHas('task', static fn ($query) => $query->where('user_id', $user->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($approvals->count() !== count(array_unique($approvalIds))) {
                throw new HttpException(404, 'One or more approvals were not found.');
            }
            if ($approvals->contains(static fn (PendingApproval $approval): bool => $approval->status !== APPROVAL_STATUS_PENDING)) {
                throw new HttpException(409, 'Every selected approval must still be pending.');
            }
            if ($approvals->contains(static fn (PendingApproval $approval): bool => (
                $approval->deferredAction?->status === DEFERRED_TOOL_ACTION_PENDING
            ))) {
                throw new HttpException(422, 'Deferred actions must be cancelled or released individually.');
            }
            if ($approvals->pluck('action')->unique()->count() !== 1) {
                throw new HttpException(422, 'Batch decisions may only include the same action type.');
            }

            $expiredApprovals = $approvals->filter(
                static fn (PendingApproval $approval): bool => $approval->expires_at !== null && $approval->expires_at->isPast(),
            );
            if ($expiredApprovals->isNotEmpty()) {
                foreach ($expiredApprovals as $expiredApproval) {
                    $this->expire($expiredApproval);
                }

                return ['expired' => true, 'count' => count($approvalIds)];
            }

            $agentRuns = [];
            foreach ($approvals as $approval) {
                $agentRun = $approval->agentRun;
                $task = $approval->task;
                if ($agentRun === null || $task === null) {
                    throw new HttpException(409, 'A selected approval is no longer linked to an active task.');
                }
                $approval->update([
                    'status' => $isApproved ? APPROVAL_STATUS_APPROVED : APPROVAL_STATUS_REJECTED,
                    'decided_by' => $user->id,
                    'decision_note' => $decisionNote,
                    'decided_at' => now(),
                ]);
                if ($isApproved) {
                    $this->activityLogger->recordEvent($agentRun, 'tool.approval_granted', [
                        'approval_id' => $approval->id,
                        'tool_name' => $approval->payload['tool_name'] ?? null,
                        'batch' => true,
                    ]);
                    $agentRuns[$agentRun->id] = $agentRun;
                } else {
                    $this->failApprovedTask($agentRun, $task, 'The user rejected the requested tool action.');
                    $this->activityLogger->recordEvent($agentRun, 'tool.approval_rejected', [
                        'approval_id' => $approval->id,
                        'tool_name' => $approval->payload['tool_name'] ?? null,
                        'batch' => true,
                    ]);
                }
            }

            foreach ($agentRuns as $agentRun) {
                RunAgentJob::dispatch(
                    $agentRun->id,
                    $this->agentRegistry->timeoutSeconds($agentRun->agent_name),
                )->afterCommit();
            }

            return ['expired' => false, 'count' => $approvals->count()];
        });

        if ($result['expired']) {
            throw new HttpException(410, 'One or more selected approvals have expired.');
        }

        return $this->success([
            'decided_count' => $result['count'],
            'status' => $isApproved ? APPROVAL_STATUS_APPROVED : APPROVAL_STATUS_REJECTED,
        ], UPDATED_SUCCESSFULLY);
    }

    private function approvalForUpdate(User $user, int $approvalId): PendingApproval
    {
        return PendingApproval::query()
            ->whereHas('task', static function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->lockForUpdate()
            ->findOrFail($approvalId);
    }

    private function expirePendingApprovals(User $user): void
    {
        $expiredApprovals = PendingApproval::query()
            ->where('status', APPROVAL_STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereHas('task', static function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->get();

        foreach ($expiredApprovals as $approval) {
            DB::transaction(function () use ($user, $approval): void {
                $approvalForUpdate = $this->approvalForUpdate($user, $approval->id);

                if ($approvalForUpdate->status === APPROVAL_STATUS_PENDING
                    && $approvalForUpdate->expires_at !== null
                    && $approvalForUpdate->expires_at->isPast()) {
                    $this->expire($approvalForUpdate);
                }
            });
        }
    }

    private function expire(PendingApproval $approval): void
    {
        $approval->update([
            'status' => APPROVAL_STATUS_REJECTED,
            'decision_note' => 'Approval expired.',
            'decided_at' => now(),
        ]);

        $agentRun = $approval->agentRun;
        $task = $approval->task;

        if ($agentRun !== null && $task !== null) {
            $this->failApprovedTask($agentRun, $task, 'The requested tool approval expired.');
            $this->activityLogger->recordEvent($agentRun, 'tool.approval_expired', [
                'approval_id' => $approval->id,
                'tool_name' => $approval->payload['tool_name'] ?? null,
            ]);
        }
    }

    private function failApprovedTask(
        AgentRun $agentRun,
        Task $task,
        string $failureMessage,
    ): void {
        $agentRun->update([
            'status' => AGENT_RUN_STATUS_FAILED,
            'error_message' => $failureMessage,
            'completed_at' => now(),
        ]);
        $task->update([
            'status' => TASK_STATUS_FAILED,
            'error_message' => $failureMessage,
            'completed_at' => now(),
        ]);
    }
}
