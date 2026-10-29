<?php

namespace App\Services;

use App\Models\KnowledgeSystemSetting;

class KnowledgeSettingsService
{
    /**
     * @return array{
     *     maximum_upload_kilobytes: int,
     *     maximum_extracted_characters: int,
     *     maximum_chunks_per_document: int,
     *     chunk_characters: int,
     *     chunk_overlap_characters: int
     * }
     */
    public function current(): array
    {
        $stored = KnowledgeSystemSetting::query()->find(1);
        $settings = config('knowledge');

        return [
            'maximum_upload_kilobytes' => $stored?->maximum_upload_kilobytes ?? (int) $settings['maximum_upload_kilobytes'],
            'maximum_extracted_characters' => $stored?->maximum_extracted_characters ?? (int) $settings['maximum_extracted_characters'],
            'maximum_chunks_per_document' => $stored?->maximum_chunks_per_document ?? (int) $settings['maximum_chunks_per_document'],
            'chunk_characters' => $stored?->chunk_characters ?? (int) $settings['chunk_characters'],
            'chunk_overlap_characters' => $stored?->chunk_overlap_characters ?? (int) $settings['chunk_overlap_characters'],
        ];
    }

    /**
     * @param  array{
     *     maximum_upload_kilobytes: int,
     *     maximum_extracted_characters: int,
     *     maximum_chunks_per_document: int,
     *     chunk_characters: int,
     *     chunk_overlap_characters: int
     * }  $settings
     */
    public function update(array $settings): array
    {
        $stored = KnowledgeSystemSetting::query()->updateOrCreate(['id' => 1], $settings);

        return [
            'maximum_upload_kilobytes' => $stored->maximum_upload_kilobytes,
            'maximum_extracted_characters' => $stored->maximum_extracted_characters,
            'maximum_chunks_per_document' => $stored->maximum_chunks_per_document,
            'chunk_characters' => $stored->chunk_characters,
            'chunk_overlap_characters' => $stored->chunk_overlap_characters,
        ];
    }
}
