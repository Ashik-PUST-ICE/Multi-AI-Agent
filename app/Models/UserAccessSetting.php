<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAccessSetting extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'access_mode',
        'agent_access_modes',
        'notification_preferences',
        'quiet_hours_start',
        'quiet_hours_end',
        'digest_time',
    ];

    protected function casts(): array
    {
        return [
            'access_mode' => 'integer',
            'agent_access_modes' => 'array',
            'notification_preferences' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
