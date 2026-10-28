<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'status' => TASK_STATUS_PENDING,
        'mode' => TASK_MODE_SANDBOX,
    ];

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'mode',
        'input',
        'result',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'input' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
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

    public function modeTransitions(): HasMany
    {
        return $this->hasMany(ModeTransition::class);
    }
}
