<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeDocument extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'original_name',
        'disk_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'status',
        'error_message',
        'chunk_count',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'status' => 'integer',
            'chunk_count' => 'integer',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}
