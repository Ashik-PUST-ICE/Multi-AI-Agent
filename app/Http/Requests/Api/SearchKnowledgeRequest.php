<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SearchKnowledgeRequest extends FormRequest
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
            'query' => ['required', 'string', 'max:2000'],
            'document_ids' => ['sometimes', 'array', 'max:100'],
            'document_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }
}
