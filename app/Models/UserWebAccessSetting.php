<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWebAccessSetting extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'search_provider',
        'search_api_key',
        'allowed_domains',
        'denied_domains',
    ];

    protected function casts(): array
    {
        return [
            'search_api_key' => 'encrypted',
            'allowed_domains' => 'array',
            'denied_domains' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
