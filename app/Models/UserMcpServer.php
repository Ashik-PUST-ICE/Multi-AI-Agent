<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMcpServer extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'name',
        'endpoint',
        'access_token',
        'discovered_tools',
        'approved_tools',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'discovered_tools' => 'array',
            'approved_tools' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
