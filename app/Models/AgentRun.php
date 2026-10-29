<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    protected $attributes = [
        'status' => AGENT_RUN_STATUS_PENDING,
        'mode' => TASK_MODE_SANDBOX,
    ];

    protected $fillable = [
        'task_id',
        'agent_name',
        'status',
        'mode',
        'input',
        'output',
        'token_usage',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'mode' => 'string',
            'input' => 'array',
            'output' => 'array',
            'token_usage' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $agentRun): void {
            $task = Task::query()->find($agentRun->task_id);
            if ($task === null) {
                return;
            }

            $agentRun->mode = $task->mode;
            $agentRun->trace_id ??= $task->trace_id;
            $agentRun->trace_span_id ??= bin2hex(random_bytes(8));
            $agentRun->parent_span_id ??= $task->trace_span_id;
        });

        static::updating(function (self $agentRun): void {
            if ($agentRun->isDirty('mode')) {
                throw new \LogicException('An agent run mode is immutable; create it from a task in the intended mode.');
            }
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class);
    }

    public function pendingApprovals(): HasMany
    {
        return $this->hasMany(PendingApproval::class);
    }

    public function runEvents(): HasMany
    {
        return $this->hasMany(RunEvent::class);
    }
}
