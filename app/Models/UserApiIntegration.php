<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserApiIntegration extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'name',
        'endpoint',
        'access_token',
        'allowed_methods',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'allowed_methods' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
