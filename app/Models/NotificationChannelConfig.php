<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannelConfig extends Model
{
    protected $fillable = ['user_id', 'tenant_id', 'channel', 'enabled', 'settings'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
