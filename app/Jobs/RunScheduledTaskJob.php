<?php

namespace App\Jobs;

use App\Models\ScheduledTaskRun;
use App\Models\Task;
use App\Services\AgentActivityLogger;
use App\Services\OrchestratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunScheduledTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $scheduledTaskRunId) {}

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('scheduled-task-run-'.$this->scheduledTaskRunId))
                ->expireAfter(180),
        ];
    }

    public function handle(OrchestratorService $orchestrator): void
    {
        $runData = DB::transaction(function (): ?array {
            $run = ScheduledTaskRun::query()->lockForUpdate()->find($this->scheduledTaskRunId);
            if ($run === null || in_array($run->status, [
                SCHEDULED_TASK_RUN_DISPATCHED,
                SCHEDULED_TASK_RUN_SKIPPED,
                SCHEDULED_TASK_RUN_FAILED,
                SCHEDULED_TASK_RUN_CANCELLED,
            ], true)) {
                return null;
            }

            $existingTask = Task::withTrashed()->where('scheduled_task_run_id', $run->id)->first();
            if ($existingTask !== null) {
                $run->update([
                    'task_id' => $existingTask->id,
                    'status' => SCHEDULED_TASK_RUN_DISPATCHED,
                    'dispatched_at' => $run->dispatched_at ?? now(),
                ]);

                return null;
            }

            $schedule = $run->schedule;
            if ($schedule === null || $schedule->cancelled_at !== null || $run->scheduled_for->lt(now()->subMinutes(15))) {
                $run->update([
                    'status' => $schedule?->cancelled_at !== null ? SCHEDULED_TASK_RUN_CANCELLED : SCHEDULED_TASK_RUN_SKIPPED,
                    'completed_at' => now(),
                    'error_message' => 'Schedule was cancelled or this queued occurrence became stale.',
                ]);

                return null;
            }

            $run->update(['status' => SCHEDULED_TASK_RUN_RUNNING, 'error_message' => null]);

            return [
                'run_id' => (int) $run->id,
                'user_id' => (int) $run->user_id,
                'title' => $schedule->title,
                'prompt' => $schedule->prompt,
                'task_brief_reviewed' => $schedule->task_brief_reviewed,
            ];
        });

        if ($runData === null) {
            return;
        }

        $run = ScheduledTaskRun::query()->findOrFail($runData['run_id']);
        $user = $run->user()->firstOrFail();
        $response = $orchestrator->submit($user, [
            'title' => $runData['title'],
            'prompt' => $runData['prompt'],
            'scheduled_task_run_id' => $run->id,
            'scheduled_task_brief_reviewed' => $runData['task_brief_reviewed'],
        ]);

        if ($response->getStatusCode() !== 202) {
            $payload = $response->getData(true);
            $run->update([
                'status' => SCHEDULED_TASK_RUN_FAILED,
                'completed_at' => now(),
                'error_message' => mb_substr($this->safeError((string) ($payload['message'] ?? 'Scheduled task was rejected.')), 0, 1000),
            ]);

            return;
        }

        $task = Task::query()->where('scheduled_task_run_id', $run->id)->firstOrFail();
        $run->update([
            'task_id' => $task->id,
            'status' => SCHEDULED_TASK_RUN_DISPATCHED,
            'dispatched_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        ScheduledTaskRun::query()
            ->whereKey($this->scheduledTaskRunId)
            ->whereIn('status', [SCHEDULED_TASK_RUN_PENDING, SCHEDULED_TASK_RUN_RUNNING])
            ->update([
                'status' => SCHEDULED_TASK_RUN_FAILED,
                'completed_at' => now(),
                'error_message' => mb_substr($this->safeError($exception->getMessage()), 0, 1000),
            ]);
    }

    private function safeError(string $message): string
    {
        return (string) app(AgentActivityLogger::class)->redactValue(['error' => $message])['error'];
    }
}
