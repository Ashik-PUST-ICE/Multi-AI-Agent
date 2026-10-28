<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingApproval extends Model
{
    protected $attributes = [
        'status' => APPROVAL_STATUS_PENDING,
    ];

    protected $fillable = [
        'task_id',
        'agent_run_id',
        'action',
        'requested_by',
        'payload',
        'status',
        'decided_by',
        'decision_note',
        'expires_at',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'payload' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
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

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
