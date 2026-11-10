<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSkillTestCase extends Model
{
    protected $fillable = [
        'user_skill_id',
        'name',
        'prompt',
        'reference_answer',
        'criteria',
    ];

    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(UserSkill::class, 'user_skill_id');
    }
}
