<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMemory extends Model
{
    use BelongsToTenant;

    protected $table = 'user_memories';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'scope_type',
        'task_id',
        'status',
        'created_by_user_id',
        'source_type',
        'superseded_by_id',
        'type',
        'label',
        'content',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'integer',
            'scope_type' => 'integer',
            'status' => 'integer',
            'created_by_user_id' => 'integer',
            'source_type' => 'integer',
            'superseded_by_id' => 'integer',
            'task_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
