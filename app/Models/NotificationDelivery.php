<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_id',
        'user_id',
        'channel',
        'status',
        'attempts',
        'provider_reference',
        'failure_reason',
        'attempted_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'attempts' => 'integer',
            'attempted_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(UserNotification::class);
    }
}
