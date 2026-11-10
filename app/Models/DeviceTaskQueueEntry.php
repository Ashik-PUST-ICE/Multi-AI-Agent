<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceTaskQueueEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'task_id',
        'device_token_id',
        'idempotency_key',
        'status',
        'priority',
        'attempt_count',
        'expires_at',
        'confirmed_at',
        'last_delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'attempt_count' => 'integer',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'last_delivered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
