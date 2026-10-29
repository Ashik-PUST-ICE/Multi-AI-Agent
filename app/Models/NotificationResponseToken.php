<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationResponseToken extends Model
{
    protected $fillable = [
        'pending_approval_id',
        'user_id',
        'token_hash',
        'decision',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(PendingApproval::class, 'pending_approval_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
