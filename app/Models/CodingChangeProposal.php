<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodingChangeProposal extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'task_id',
        'workspace_label',
        'title',
        'plan',
        'diff',
        'diff_sha256',
        'status',
        'secret_scan_status',
        'decision_at',
    ];

    protected function casts(): array
    {
        return [
            'decision_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
