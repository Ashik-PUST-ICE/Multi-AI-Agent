<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class UserMemory extends Model
{
    use BelongsToTenant;

    protected $table = 'user_memories';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'type',
        'label',
        'content',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
