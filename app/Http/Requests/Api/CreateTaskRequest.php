<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateTaskRequest extends FormRequest
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
            'success_criteria' => ['sometimes', 'array', 'max:10'],
            'success_criteria.*' => ['required', 'string', 'max:500', 'distinct'],
            'plan_review' => ['sometimes', 'array'],
            'plan_review.required' => ['sometimes', 'boolean'],
            'plan_review.status' => ['sometimes', 'string', 'in:pending,approved,rejected'],
            'plan_review.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'max_cost' => ['sometimes', 'nullable', 'numeric', 'min:0.000001', 'max:1000000'],
            'agent_pack' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ];
    }
}
