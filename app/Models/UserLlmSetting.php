<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLlmSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'provider',
        'model',
        'approved_models',
        'agent_models',
        'fallback_provider',
        'fallback_model',
        'model_pricing',
        'max_task_cost',
        'budget_currency',
        'agent_brains',
        'routing_policy',
        'embedding_provider',
        'embedding_model',
    ];

    protected function casts(): array
    {
        return [
            'approved_models' => 'array',
            'agent_models' => 'array',
            'model_pricing' => 'array',
            'max_task_cost' => 'float',
            'agent_brains' => 'array',
            'routing_policy' => 'string',
            'embedding_provider' => 'string',
            'embedding_model' => 'string',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
