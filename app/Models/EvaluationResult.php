<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationResult extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'evaluation_case_id',
        'agent_name',
        'agent_spec_version',
        'provider',
        'model',
        'status',
        'score',
        'metrics',
        'output',
        'token_usage',
        'cost_amount',
        'cost_currency',
        'duration_ms',
        'error_message',
    ];

    protected $hidden = [
        'output',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'agent_spec_version' => 'integer',
            'score' => 'float',
            'metrics' => 'array',
            'token_usage' => 'array',
            'cost_amount' => 'float',
            'duration_ms' => 'integer',
        ];
    }

    public function evaluationCase(): BelongsTo
    {
        return $this->belongsTo(EvaluationCase::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
