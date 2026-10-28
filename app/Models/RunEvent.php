<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunEvent extends Model
{
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
