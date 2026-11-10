<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserSkill extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'name',
        'slug',
        'description',
        'visibility',
        'source',
        'publisher_claim',
        'source_reference',
        'status',
        'current_version',
        'evaluation_auto_disable',
        'evaluation_minimum_score',
    ];

    protected function casts(): array
    {
        return [
            'current_version' => 'integer',
            'evaluation_auto_disable' => 'boolean',
            'evaluation_minimum_score' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(UserSkillVersion::class);
    }

    public function testCases(): HasMany
    {
        return $this->hasMany(UserSkillTestCase::class);
    }

    public function evaluationRuns(): HasMany
    {
        return $this->hasMany(UserSkillEvaluationRun::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(UserSkillUsage::class);
    }
}
