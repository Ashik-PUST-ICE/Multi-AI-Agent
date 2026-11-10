<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSkillUsage extends Model
{
    protected $fillable = [
        'user_skill_id',
        'user_skill_version_id',
        'task_id',
        'user_id',
        'tenant_id',
        'status',
        'mode',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'cost_by_currency',
        'duration_ms',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_by_currency' => 'array',
            'duration_ms' => 'integer',
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
