<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
