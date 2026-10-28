<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTrustHistory extends Model
{
    protected $table = 'agent_trust_history';

    protected $fillable = [
        'user_id',
        'agent_name',
        'from_level',
        'to_level',
        'change_type',
        'reason',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'from_level' => 'integer',
            'to_level' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
