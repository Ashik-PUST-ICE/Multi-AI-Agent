<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateAssistantMessageRequest extends FormRequest
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
            'conversation_id' => ['sometimes', 'integer', 'min:1'],
            'content' => ['required', 'string', 'min:1', 'max:20000'],
            'task_brief_review_token' => ['sometimes', 'string', 'max:4096'],
        ];
    }
}
