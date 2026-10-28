<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTrust extends Model
{
    protected $table = 'agent_trust';

    protected $fillable = [
        'user_id',
        'agent_name',
        'trust_level',
    ];

    protected function casts(): array
    {
        return [
            'trust_level' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
