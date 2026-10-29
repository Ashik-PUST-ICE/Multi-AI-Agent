<?php

return [
    'disk' => 'local',
    'ocr_binary' => env('KNOWLEDGE_OCR_BINARY', 'tesseract'),
    'maximum_upload_kilobytes' => 10240,
    'maximum_extracted_characters' => 1_000_000,
    'maximum_chunks_per_document' => 1000,
    'chunk_characters' => 1400,
    'chunk_overlap_characters' => 160,
];
