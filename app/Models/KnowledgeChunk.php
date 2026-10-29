<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'knowledge_document_id',
        'user_id',
        'tenant_id',
        'chunk_index',
        'content',
        'embedding',
        'embedding_provider',
        'embedding_model',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'embedding' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }
}
