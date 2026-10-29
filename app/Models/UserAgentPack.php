<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAgentPack extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'agent_pack_id',
        'status',
    ];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(AgentPack::class, 'agent_pack_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
