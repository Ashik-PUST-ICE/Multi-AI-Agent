<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSpec extends Model
{
    protected $fillable = [
        'agent_name',
        'version',
        'status',
        'role',
        'system_prompt',
        'input_schema',
        'output_schema',
        'allowed_tools',
        'minimum_trust_level',
        'default_model',
        'fallback_model',
        'max_steps',
        'max_tokens',
        'timeout_seconds',
        'stop_conditions',
        'success_criteria',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => 'integer',
            'input_schema' => 'array',
            'output_schema' => 'array',
            'allowed_tools' => 'array',
            'minimum_trust_level' => 'integer',
            'max_steps' => 'integer',
            'max_tokens' => 'integer',
            'timeout_seconds' => 'integer',
            'stop_conditions' => 'array',
            'success_criteria' => 'array',
            'created_by' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
