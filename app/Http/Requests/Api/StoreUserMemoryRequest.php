<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserMemoryRequest extends FormRequest
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
            'type' => ['required', 'integer', 'in:'.USER_MEMORY_TYPE_SHORT_TERM.','.USER_MEMORY_TYPE_LONG_TERM],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:5000'],
            'expires_in_days' => ['required_if:type,'.USER_MEMORY_TYPE_SHORT_TERM, 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
