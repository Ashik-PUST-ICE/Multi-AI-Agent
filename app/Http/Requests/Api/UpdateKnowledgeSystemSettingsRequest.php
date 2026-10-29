<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateKnowledgeSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('knowledge_settings.manage') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'maximum_upload_kilobytes' => ['required', 'integer', 'min:1', 'max:51200'],
            'maximum_extracted_characters' => ['required', 'integer', 'min:10000', 'max:2000000'],
            'maximum_chunks_per_document' => ['required', 'integer', 'min:1', 'max:5000'],
            'chunk_characters' => ['required', 'integer', 'min:200', 'max:5000'],
            'chunk_overlap_characters' => ['required', 'integer', 'min:0', 'max:4999'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $this->integer('chunk_overlap_characters')
                    >= $this->integer('chunk_characters')
                ) {
                    $validator->errors()->add(
                        'chunk_overlap_characters',
                        'Chunk overlap must be smaller than the chunk size.',
                    );
                }
            },
        ];
    }
}
