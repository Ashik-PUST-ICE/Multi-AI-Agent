<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    protected $attributes = [
        'status' => AGENT_RUN_STATUS_PENDING,
    ];

    protected $fillable = [
        'task_id',
        'agent_name',
        'status',
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
            'input' => 'array',
            'output' => 'array',
            'token_usage' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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
