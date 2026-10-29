<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class EstimateTaskRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'max:20000'],
            'max_cost' => ['sometimes', 'nullable', 'numeric', 'min:0.000001', 'max:1000000'],
            'agent_pack' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'knowledge_document_ids' => ['sometimes', 'array', 'max:100'],
            'knowledge_document_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
