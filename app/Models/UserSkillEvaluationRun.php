<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSkillEvaluationRun extends Model
{
    protected $fillable = [
        'user_skill_id',
        'user_skill_version_id',
        'initiated_by',
        'provider',
        'model',
        'status',
        'score',
        'case_count',
        'case_results',
        'token_usage',
        'failure_message',
        'completed_at',
    ];

    protected $hidden = ['failure_message'];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'case_count' => 'integer',
            'case_results' => 'array',
            'token_usage' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(UserSkill::class, 'user_skill_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(UserSkillVersion::class, 'user_skill_version_id');
    }
}
