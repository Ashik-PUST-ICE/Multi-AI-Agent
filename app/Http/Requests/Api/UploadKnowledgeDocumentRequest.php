<?php

namespace App\Http\Requests\Api;

use App\Services\KnowledgeSettingsService;
use Illuminate\Foundation\Http\FormRequest;

class UploadKnowledgeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.app(KnowledgeSettingsService::class)->current()['maximum_upload_kilobytes'],
                'mimes:pdf,xlsx,xls,csv,txt,md,png,jpg,jpeg,webp',
            ],
        ];
    }
}
