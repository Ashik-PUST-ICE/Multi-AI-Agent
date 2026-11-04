<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentPack extends Model
{
    protected $fillable = [
        'slug',
        'version',
        'name',
        'description',
        'definition',
        'definition_sha256',
        'integrity_signature',
        'status',
        'is_builtin',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => 'integer',
            'definition' => 'array',
            'definition_sha256' => 'string',
            'integrity_signature' => 'string',
            'is_builtin' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    public function installations(): HasMany
    {
        return $this->hasMany(UserAgentPack::class);
    }
}
