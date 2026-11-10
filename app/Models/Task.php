<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\SkillUsageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class Task extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $hidden = [
        'vision_image_path',
        'vision_image_mime_type',
        'vision_image_expires_at',
    ];

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
        'scheduled_task_run_id',
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
            'vision_image_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $task): void {
            $task->objective_sha256 ??= hash('sha256', (string) $task->description);
            $task->trace_id ??= bin2hex(random_bytes(16));
            $task->trace_span_id ??= bin2hex(random_bytes(8));

            if (! in_array($task->mode, [TASK_MODE_SANDBOX, TASK_MODE_LIVE], true)) {
                throw new \InvalidArgumentException('A task must use a supported execution mode.');
            }
        });

        static::created(function (self $task): void {
            app(SkillUsageService::class)->recordSubmission($task);
        });

        static::updated(function (self $task): void {
            if ($task->wasChanged('status')
                && in_array($task->status, [TASK_STATUS_COMPLETED, TASK_STATUS_FAILED, TASK_STATUS_CANCELLED], true)) {
                app(SkillUsageService::class)->recordTerminalOutcome($task);
            }
        });

        static::updating(function (self $task): void {
            if ($task->isDirty('mode')) {
                throw new \LogicException('A task mode is immutable; use the explicit Sandbox-to-Live transition.');
            }
        });

        static::deleting(function (self $task): void {
            if ($task->vision_image_path !== null
                && ! Storage::disk('local')->delete($task->vision_image_path)) {
                throw new RuntimeException('The private camera image could not be deleted with its task.');
            }
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

    public function skillUsages(): HasMany
    {
        return $this->hasMany(UserSkillUsage::class);
    }

    public function scheduledRun(): BelongsTo
    {
        return $this->belongsTo(ScheduledTaskRun::class, 'scheduled_task_run_id');
    }

    public function deviceQueueEntry(): HasOne
    {
        return $this->hasOne(DeviceTaskQueueEntry::class);
    }
}
