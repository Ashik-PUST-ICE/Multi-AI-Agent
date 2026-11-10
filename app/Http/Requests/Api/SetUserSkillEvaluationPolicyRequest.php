<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SetUserSkillEvaluationPolicyRequest extends FormRequest
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
            'auto_disable' => ['required', 'boolean'],
            'minimum_score' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('auto_disable') && $this->input('minimum_score') === null) {
                    $validator->errors()->add(
                        'minimum_score',
                        'Set a minimum evaluation score to enable automatic deactivation.',
                    );
                }
            },
        ];
    }
}
