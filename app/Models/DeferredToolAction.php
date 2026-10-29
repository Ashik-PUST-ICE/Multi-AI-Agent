<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeferredToolAction extends Model
{
    protected $fillable = [
        'pending_approval_id',
        'policy_id',
        'task_id',
        'agent_run_id',
        'agent_name',
        'tool_name',
        'tool_input',
        'approval_signature',
        'scheduled_at',
        'status',
        'cancelled_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'tool_input' => 'encrypted:array',
            'status' => 'integer',
            'cancelled_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(PendingApproval::class, 'pending_approval_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(UserAccessPolicy::class, 'policy_id');
    }
}
