<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLlmSetting extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'model',
        'approved_models',
        'agent_models',
        'fallback_provider',
        'fallback_model',
    ];

    protected function casts(): array
    {
        return [
            'approved_models' => 'array',
            'agent_models' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
