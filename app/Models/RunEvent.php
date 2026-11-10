<?php

namespace App\Models;

use App\Events\TaskProgressUpdated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class RunEvent extends Model
{
    protected static function booted(): void
    {
        static::created(function (self $event): void {
            $task = $event->task ?? Task::query()->find($event->task_id);
            $userId = $task?->user_id;

            if ($task !== null && $userId !== null) {
                event(new TaskProgressUpdated(
                    (int) $task->id,
                    (int) $userId,
                    (int) $event->id,
                    (string) $event->event_type,
                    (int) $event->sequence,
                ));
            }
        });

        static::creating(function (self $event): void {
            $event->status = self::eventStatus((string) $event->event_type);
            $agentRun = $event->agent_run_id === null
                ? null
                : AgentRun::query()->find($event->agent_run_id);
            $task = $agentRun?->task
                ?? ($event->task_id === null ? null : Task::query()->find($event->task_id));
            if ($task === null) {
                return;
            }

            $event->task_id = $task->id;
            $event->parent_run_id = $agentRun?->id;
            $event->handoff_to = null;
            $targetRunId = $event->event_type === 'agent.handoff'
                ? ($event->payload['next_agent_run_id'] ?? null)
                : null;
            if ((is_int($targetRunId) || (is_string($targetRunId) && ctype_digit($targetRunId)))
                && DB::table('agent_runs')
                    ->where('id', (int) $targetRunId)
                    ->where('task_id', $task->id)
                    ->exists()) {
                $event->handoff_to = (int) $targetRunId;
            }
            $event->mode = $agentRun?->mode ?? $task->mode;
            $event->trace_id ??= $task->trace_id;
            $event->trace_span_id ??= bin2hex(random_bytes(8));
            $event->parent_span_id ??= $agentRun?->trace_span_id ?? $task->trace_span_id;
        });

        static::updating(function (self $event): void {
            if ($event->isDirty('mode')) {
                throw new \LogicException('A recorded event mode is immutable.');
            }
        });
    }

    protected $attributes = [
        'mode' => TASK_MODE_SANDBOX,
    ];

    protected $fillable = [
        'task_id',
        'agent_run_id',
        'event_type',
        'mode',
        'sequence',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => 'string',
            'sequence' => 'integer',
            'payload' => 'array',
            'parent_run_id' => 'integer',
            'handoff_to' => 'integer',
            'status' => 'string',
            'occurred_at' => 'datetime',
        ];
    }

    private static function eventStatus(string $eventType): string
    {
        return match ($eventType) {
            'agent.started', 'tool.started' => 'running',
            'agent.completed', 'task.completed', 'tool.completed' => 'completed',
            'agent.failed', 'task.failed', 'tool.failed', 'skeptic.rejected',
            'observability.silent_failure', 'observability.stagnation' => 'failed',
            'tool.approval_required', 'agent.waiting_for_approval' => 'waiting',
            default => 'recorded',
        };
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
