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
        'agent_brains',
        'routing_policy',
    ];

    protected function casts(): array
    {
        return [
            'approved_models' => 'array',
            'agent_models' => 'array',
            'model_pricing' => 'array',
            'agent_brains' => 'array',
            'routing_policy' => 'string',
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
