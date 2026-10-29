<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AgentMessage extends Model
{
    protected $attributes = [
        'mode' => TASK_MODE_SANDBOX,
    ];

    protected $fillable = [
        'task_id',
        'agent_run_id',
        'mode',
        'role',
        'content',
        'sequence',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'mode' => 'string',
            'sequence' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            $agentRun = $message->agent_run_id === null
                ? null
                : AgentRun::query()->find($message->agent_run_id);
            $task = $agentRun?->task
                ?? ($message->task_id === null ? null : Task::query()->find($message->task_id));
            if ($task === null) {
                return;
            }

            $message->task_id = $task->id;
            $message->mode = $agentRun?->mode ?? $task->mode;
        });

        static::updating(function (self $message): void {
            if ($message->isDirty('mode')) {
                throw new LogicException('A recorded message mode is immutable.');
            }
        });
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
