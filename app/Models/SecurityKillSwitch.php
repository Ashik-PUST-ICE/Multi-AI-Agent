<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityKillSwitch extends Model
{
    protected $fillable = [
        'scope_type',
        'scope_id',
        'enabled',
        'triggered_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => 'integer',
            'scope_id' => 'integer',
            'enabled' => 'integer',
            'triggered_by' => 'integer',
        ];
    }
}
