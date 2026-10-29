<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'scope_type' => ['sometimes', 'integer', 'in:'.USER_MEMORY_SCOPE_USER.','.USER_MEMORY_SCOPE_TASK],
            'task_id' => [
                'required_if:scope_type,'.USER_MEMORY_SCOPE_TASK,
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')->where('user_id', $this->user()?->id),
            ],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:5000'],
            'expires_in_days' => ['required_if:type,'.USER_MEMORY_TYPE_SHORT_TERM, 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
