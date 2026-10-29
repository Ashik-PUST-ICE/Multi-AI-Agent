<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAccessPolicy extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'task_id',
        'agent_name',
        'tool_name',
        'input_constraints',
        'scope',
        'maximum_uses',
        'uses_count',
        'undo_window_seconds',
        'status',
        'expires_at',
        'last_reminder_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'integer',
            'input_constraints' => 'array',
            'maximum_uses' => 'integer',
            'uses_count' => 'integer',
            'undo_window_seconds' => 'integer',
            'status' => 'integer',
            'expires_at' => 'datetime',
            'last_reminder_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
