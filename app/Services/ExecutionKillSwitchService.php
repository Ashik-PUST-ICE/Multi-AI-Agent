<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\SecurityKillSwitch;
use App\Models\Task;
use App\Models\User;
use App\Tools\ExecutionHaltedException;
use Illuminate\Support\Facades\DB;

class ExecutionKillSwitchService
{
    public function assertCanContinue(AgentRun $run): void
    {
        $task = $run->task;
        if ($task === null) {
            throw new ExecutionHaltedException('Execution stopped because its task is unavailable.');
        }

        if ($this->enabled(KILL_SWITCH_SCOPE_SYSTEM, 0)) {
            throw new ExecutionHaltedException('Execution is halted by the system kill switch.');
        }
        if ($this->enabled(KILL_SWITCH_SCOPE_TASK, $task->id)) {
            throw new ExecutionHaltedException('Execution is halted by the task owner.');
        }
        if ($this->enabled(KILL_SWITCH_SCOPE_AGENT_RUN, $run->id)) {
            throw new ExecutionHaltedException('Execution is halted for this agent run.');
        }
        $objectiveHash = hash('sha256', (string) $task->description);
        if (
            ! is_string($task->objective_sha256)
            || ! hash_equals($task->objective_sha256, $objectiveHash)
        ) {
            throw new ExecutionHaltedException('Execution is halted because the task objective changed unexpectedly.');
        }
        if (
            in_array($task->status, [TASK_STATUS_CANCELLED, TASK_STATUS_FAILED, TASK_STATUS_COMPLETED], true)
            || in_array($run->status, [AGENT_RUN_STATUS_CANCELLED, AGENT_RUN_STATUS_FAILED, AGENT_RUN_STATUS_COMPLETED], true)
        ) {
            throw new ExecutionHaltedException('Execution is no longer active.');
        }
    }

    public function stopTask(User $actor, Task $task, ?AgentRun $run = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($actor, $task, $run, $reason): void {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $scopeType = $run === null ? KILL_SWITCH_SCOPE_TASK : KILL_SWITCH_SCOPE_AGENT_RUN;
            $scopeId = $run === null ? $lockedTask->id : $run->id;
            SecurityKillSwitch::query()->updateOrCreate(
                ['scope_type' => $scopeType, 'scope_id' => $scopeId],
                [
                    'enabled' => 1,
                    'triggered_by' => $actor->id,
                    'reason' => $reason ?? 'Stopped by task owner.',
                ],
            );

            if ($run !== null) {
                $lockedRun = $lockedTask->agentRuns()->lockForUpdate()->findOrFail($run->id);
                $lockedTask->agentRuns()
                    ->whereIn('status', [AGENT_RUN_STATUS_PENDING, AGENT_RUN_STATUS_RUNNING])
                    ->update([
                        'status' => AGENT_RUN_STATUS_CANCELLED,
                        'error_message' => 'Stopped by task owner.',
                        'completed_at' => now(),
                    ]);
                $lockedTask->update([
                    'status' => TASK_STATUS_CANCELLED,
                    'error_message' => 'Stopped by task owner.',
                    'completed_at' => now(),
                ]);
                $this->recordEvent($lockedTask, $lockedRun->refresh(), 'execution.agent_halted', $reason);

                return;
            }

            $lockedTask->agentRuns()
                ->whereIn('status', [AGENT_RUN_STATUS_PENDING, AGENT_RUN_STATUS_RUNNING])
                ->update([
                    'status' => AGENT_RUN_STATUS_CANCELLED,
                    'error_message' => 'Stopped by task owner.',
                    'completed_at' => now(),
                ]);
            $lockedTask->update([
                'status' => TASK_STATUS_CANCELLED,
                'error_message' => 'Stopped by task owner.',
                'completed_at' => now(),
            ]);
            $this->recordEvent($lockedTask, null, 'execution.task_halted', $reason);
        });
    }

    public function setSystemSwitch(User $actor, bool $enabled, ?string $reason = null): void
    {
        SecurityKillSwitch::query()->updateOrCreate(
            ['scope_type' => KILL_SWITCH_SCOPE_SYSTEM, 'scope_id' => 0],
            [
                'enabled' => $enabled ? 1 : 0,
                'triggered_by' => $actor->id,
                'reason' => $reason,
            ],
        );
    }

    public function systemSwitchEnabled(): bool
    {
        return $this->enabled(KILL_SWITCH_SCOPE_SYSTEM, 0);
    }

    public function haltRun(AgentRun $run, string $reason): void
    {
        DB::transaction(function () use ($run, $reason): void {
            $task = Task::query()->lockForUpdate()->findOrFail($run->task_id);
            $lockedRun = $task->agentRuns()->lockForUpdate()->findOrFail($run->id);

            if (in_array($lockedRun->status, [AGENT_RUN_STATUS_COMPLETED, AGENT_RUN_STATUS_CANCELLED], true)) {
                return;
            }

            $lockedRun->update([
                'status' => AGENT_RUN_STATUS_CANCELLED,
                'error_message' => $reason,
                'completed_at' => now(),
            ]);
            $task->update([
                'status' => TASK_STATUS_CANCELLED,
                'error_message' => $reason,
                'completed_at' => now(),
            ]);
            $this->recordEvent($task, $lockedRun, 'execution.agent_halted', $reason);
        });
    }

    private function enabled(int $scopeType, int $scopeId): bool
    {
        return SecurityKillSwitch::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('enabled', 1)
            ->exists();
    }

    private function recordEvent(Task $task, ?AgentRun $run, string $eventType, ?string $reason): void
    {
        $run?->runEvents()->create([
            'task_id' => $task->id,
            'event_type' => $eventType,
            'mode' => $task->mode,
            'sequence' => (int) $run->runEvents()->max('sequence') + 1,
            'payload' => ['reason' => $reason],
            'occurred_at' => now(),
        ]);
        if ($run === null) {
            $task->runEvents()->create([
                'event_type' => $eventType,
                'mode' => $task->mode,
                'sequence' => (int) $task->runEvents()->max('sequence') + 1,
                'payload' => ['reason' => $reason],
                'occurred_at' => now(),
            ]);
        }
    }
}
