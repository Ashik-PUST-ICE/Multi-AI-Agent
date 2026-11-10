<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSkillVersion extends Model
{
    protected $fillable = [
        'user_skill_id',
        'version',
        'instructions',
        'content_sha256',
        'scan_findings',
        'verification_status',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'scan_findings' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(UserSkill::class, 'user_skill_id');
    }
}
