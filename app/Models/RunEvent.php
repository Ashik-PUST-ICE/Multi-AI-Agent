<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunEvent extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $agentRun = $event->agent_run_id === null
                ? null
                : AgentRun::query()->find($event->agent_run_id);
            $task = $agentRun?->task
                ?? ($event->task_id === null ? null : Task::query()->find($event->task_id));
            if ($task === null) {
                return;
            }

            $event->task_id = $task->id;
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
            'occurred_at' => 'datetime',
        ];
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
