<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModeTransition extends Model
{
    protected $fillable = [
        'task_id',
        'resulting_task_id',
        'user_id',
        'from_mode',
        'to_mode',
        'status',
        'blocked_requirements',
        'user_confirmed',
        'transitioned_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'blocked_requirements' => 'array',
            'user_confirmed' => 'boolean',
            'transitioned_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function resultingTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'resulting_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
