<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = [
        'name',
        'guard_name',
        'display_name',
        'module',
    ];

    protected function casts(): array
    {
        return [
            'module' => 'integer',
        ];
    }
}
