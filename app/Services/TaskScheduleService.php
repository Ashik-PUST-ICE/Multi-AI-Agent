<?php

namespace App\Services;

use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTaskRun;
use App\Models\TaskSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TaskScheduleService
{
    private const MAX_ACTIVE_SCHEDULES = 10;

    private const MAX_SCHEDULE_HORIZON_DAYS = 90;

    private const LATE_RUN_GRACE_MINUTES = 15;

    /**
     * @param  array{title: string, prompt: string, cadence: string, starts_at: string, timezone: string, expires_at?: string|null}  $data
     * @return array<string, mixed>
     */
    public function create(User $user, array $data): array
    {
        $timezone = $data['timezone'];
        $start = CarbonImmutable::parse($data['starts_at'], $timezone)->setTimezone($timezone);
        $now = CarbonImmutable::now($timezone);
        if ($start->lessThanOrEqualTo($now) || $start->greaterThan($now->addDays(self::MAX_SCHEDULE_HORIZON_DAYS))) {
            throw new InvalidArgumentException('A task schedule must start within the next 90 days.');
        }

        $expiresAt = null;
        if ($data['cadence'] !== 'once') {
            $expiresAt = CarbonImmutable::parse((string) ($data['expires_at'] ?? ''), $timezone)->setTimezone($timezone);
            if ($expiresAt->lessThanOrEqualTo($start)
                || $expiresAt->greaterThan($start->addDays(self::MAX_SCHEDULE_HORIZON_DAYS))) {
                throw new InvalidArgumentException('A recurring schedule must expire within 90 days of its first run.');
            }
        }

        $schedule = DB::transaction(function () use ($user, $data, $start, $expiresAt, $timezone): TaskSchedule {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($owner->taskSchedules()->where('enabled', 1)->count() >= self::MAX_ACTIVE_SCHEDULES) {
                throw new InvalidArgumentException('You can have at most 10 active task schedules.');
            }

            return $owner->taskSchedules()->create([
                'tenant_id' => $owner->tenant_id,
                'title' => $data['title'],
                'prompt' => $data['prompt'],
                'cadence' => $data['cadence'],
                'timezone' => $timezone,
                'local_time' => $start->format('H:i:s'),
                'weekday' => $data['cadence'] === 'weekly' ? $start->dayOfWeekIso : null,
                'next_run_at' => $start->utc(),
                'expires_at' => $expiresAt?->utc(),
                'enabled' => 1,
            ]);
        });

        return $this->scheduleData($schedule);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function index(User $user): array
    {
        return $user->taskSchedules()
            ->with(['runs' => fn ($query) => $query->latest('scheduled_for')->limit(5)])
            ->latest('id')
            ->get()
            ->map(fn (TaskSchedule $schedule): array => $this->scheduleData($schedule))
            ->all();
    }

    public function cancel(User $user, int $scheduleId): bool
    {
        return DB::transaction(function () use ($user, $scheduleId): bool {
            $schedule = $user->taskSchedules()->lockForUpdate()->findOrFail($scheduleId);
            $hasQueuedRuns = $schedule->runs()
                ->whereIn('status', [SCHEDULED_TASK_RUN_PENDING, SCHEDULED_TASK_RUN_RUNNING])
                ->exists();
            if ($schedule->enabled === 0 && ! $hasQueuedRuns) {
                return false;
            }

            $schedule->update(['enabled' => 0, 'cancelled_at' => now()]);
            $schedule->runs()
                ->whereIn('status', [SCHEDULED_TASK_RUN_PENDING, SCHEDULED_TASK_RUN_RUNNING])
                ->update([
                    'status' => SCHEDULED_TASK_RUN_CANCELLED,
                    'completed_at' => now(),
                ]);

            return true;
        });
    }

    public function dispatchDue(): int
    {
        TaskSchedule::query()
            ->where('enabled', 1)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['enabled' => 0]);

        $dueIds = TaskSchedule::query()
            ->where('enabled', 1)
            ->where('next_run_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        $dispatchIds = [];
        foreach ($dueIds as $scheduleId) {
            $runId = DB::transaction(function () use ($scheduleId): ?int {
                $schedule = TaskSchedule::query()->lockForUpdate()->find($scheduleId);
                if ($schedule === null
                    || $schedule->enabled !== 1
                    || $schedule->next_run_at->isFuture()
                    || ($schedule->expires_at !== null && $schedule->expires_at->isPast())) {
                    return null;
                }

                $scheduledFor = $schedule->next_run_at;
                $isLate = $scheduledFor->lt(now()->subMinutes(self::LATE_RUN_GRACE_MINUTES));
                $run = $schedule->runs()->firstOrCreate(
                    ['scheduled_for' => $scheduledFor],
                    [
                        'user_id' => $schedule->user_id,
                        'status' => $isLate ? SCHEDULED_TASK_RUN_SKIPPED : SCHEDULED_TASK_RUN_PENDING,
                        'completed_at' => $isLate ? now() : null,
                        'error_message' => $isLate ? 'Skipped because this occurrence was over 15 minutes late.' : null,
                    ],
                );

                $schedule->update([
                    'last_scheduled_at' => $scheduledFor,
                    ...$this->nextOccurrence($schedule, $scheduledFor),
                ]);

                return $run->status === SCHEDULED_TASK_RUN_PENDING ? (int) $run->id : null;
            });

            if ($runId !== null) {
                $dispatchIds[] = $runId;
            }
        }

        $pendingIds = ScheduledTaskRun::query()
            ->where('status', SCHEDULED_TASK_RUN_PENDING)
            ->where('scheduled_for', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('queued_at')
                    ->orWhere('queued_at', '<=', now()->subMinutes(2));
            })
            ->when($dispatchIds !== [], fn ($query) => $query->whereNotIn('id', $dispatchIds))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        $dispatchIds = [...$dispatchIds, ...$pendingIds->map(fn ($id): int => (int) $id)->all()];
        foreach ($dispatchIds as $runId) {
            RunScheduledTaskJob::dispatch((int) $runId);
            ScheduledTaskRun::query()
                ->whereKey($runId)
                ->where('status', SCHEDULED_TASK_RUN_PENDING)
                ->update(['queued_at' => now()]);
        }

        return count($dispatchIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function nextOccurrence(TaskSchedule $schedule, CarbonInterface $scheduledFor): array
    {
        if ($schedule->cadence === 'once') {
            return ['enabled' => 0];
        }

        $scheduledFor = CarbonImmutable::instance($scheduledFor);
        $local = $scheduledFor->setTimezone($schedule->timezone);
        $next = $schedule->cadence === 'daily'
            ? $local->addDay()
            : $local->addWeek();
        $next = $next->setTimeFromTimeString($schedule->local_time);
        $expiresAt = $schedule->expires_at?->setTimezone($schedule->timezone);

        if ($expiresAt !== null && $next->greaterThan($expiresAt)) {
            return ['enabled' => 0];
        }

        return ['next_run_at' => $next->utc(), 'enabled' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleData(TaskSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'title' => $schedule->title,
            'prompt' => $schedule->prompt,
            'cadence' => $schedule->cadence,
            'timezone' => $schedule->timezone,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'expires_at' => $schedule->expires_at?->toIso8601String(),
            'enabled' => $schedule->enabled === 1,
            'last_scheduled_at' => $schedule->last_scheduled_at?->toIso8601String(),
            'cancelled_at' => $schedule->cancelled_at?->toIso8601String(),
            'can_cancel' => $schedule->enabled === 1
                || $schedule->runs->contains(fn (ScheduledTaskRun $run): bool => in_array(
                    $run->status,
                    [SCHEDULED_TASK_RUN_PENDING, SCHEDULED_TASK_RUN_RUNNING],
                    true,
                )),
            'runs' => $schedule->relationLoaded('runs')
                ? $schedule->runs->map(fn (ScheduledTaskRun $run): array => [
                    'id' => $run->id,
                    'task_id' => $run->task_id,
                    'status' => $run->status,
                    'scheduled_for' => $run->scheduled_for?->toIso8601String(),
                    'error_message' => $run->error_message,
                ])->all()
                : [],
        ];
    }
}
