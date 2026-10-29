<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $attributes = [
        'status' => TASK_STATUS_PENDING,
        'mode' => TASK_MODE_SANDBOX,
    ];

    protected $fillable = [
        'user_id',
        'tenant_id',
        'title',
        'description',
        'objective_sha256',
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

    protected static function booted(): void
    {
        static::creating(function (self $task): void {
            $task->objective_sha256 ??= hash('sha256', (string) $task->description);
            $task->trace_id ??= bin2hex(random_bytes(16));
            $task->trace_span_id ??= bin2hex(random_bytes(8));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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

    public function replayShares(): HasMany
    {
        return $this->hasMany(TaskReplayShare::class);
    }

    public function modeTransitions(): HasMany
    {
        return $this->hasMany(ModeTransition::class);
    }
}
