<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskSchedule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'title',
        'prompt',
        'cadence',
        'timezone',
        'local_time',
        'weekday',
        'next_run_at',
        'expires_at',
        'enabled',
        'last_scheduled_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'enabled' => 'integer',
            'next_run_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_scheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledTaskRun::class);
    }
}
