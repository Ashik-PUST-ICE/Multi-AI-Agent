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
