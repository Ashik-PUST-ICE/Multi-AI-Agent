<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeSystemSetting extends Model
{
    protected $fillable = [
        'maximum_upload_kilobytes',
        'maximum_extracted_characters',
        'maximum_chunks_per_document',
        'chunk_characters',
        'chunk_overlap_characters',
    ];

    protected function casts(): array
    {
        return [
            'maximum_upload_kilobytes' => 'integer',
            'maximum_extracted_characters' => 'integer',
            'maximum_chunks_per_document' => 'integer',
            'chunk_characters' => 'integer',
            'chunk_overlap_characters' => 'integer',
        ];
    }
}
