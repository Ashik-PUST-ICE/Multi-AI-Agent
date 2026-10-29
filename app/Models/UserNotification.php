<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserNotification extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'type',
        'priority',
        'title',
        'message',
        'data',
        'dedupe_key',
        'status',
        'delivered_at',
        'read_at',
        'external_response_links',
        'reminder_count',
        'next_reminder_at',
        'response_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'data' => 'array',
            'status' => 'integer',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'reminder_count' => 'integer',
            'next_reminder_at' => 'datetime',
            'response_at' => 'datetime',
            'external_response_links' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }
}
