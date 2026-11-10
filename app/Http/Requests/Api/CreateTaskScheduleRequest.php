<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateTaskScheduleRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:20000'],
            'cadence' => ['required', 'string', 'in:once,daily,weekly'],
            'starts_at' => ['required', 'date', 'after:now'],
            'timezone' => ['required', 'timezone'],
            'expires_at' => ['required_if:cadence,daily,weekly', 'nullable', 'date', 'after:starts_at'],
            'task_brief_review_token' => ['sometimes', 'string', 'max:4096'],
        ];
    }
}
