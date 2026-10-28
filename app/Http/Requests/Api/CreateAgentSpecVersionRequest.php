<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateAgentSpecVersionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->specificationRules();
    }

    /**
     * @return array<string, mixed>
     */
    protected function specificationRules(): array
    {
        return [
            'role' => ['sometimes', 'string', 'max:500'],
            'system_prompt' => ['sometimes', 'string', 'max:20000'],
            'input_schema' => ['sometimes', 'array'],
            'output_schema' => ['sometimes', 'array'],
            'allowed_tools' => ['sometimes', 'array'],
            'allowed_tools.*' => ['string', 'distinct'],
            'minimum_trust_level' => ['sometimes', 'integer', 'between:0,5'],
            'default_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fallback_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'max_steps' => ['sometimes', 'integer', 'between:1,20'],
            'max_tokens' => ['sometimes', 'integer', 'between:1,128000'],
            'timeout_seconds' => ['sometimes', 'integer', 'between:1,300'],
            'stop_conditions' => ['sometimes', 'array', 'min:1'],
            'stop_conditions.*' => ['string', 'max:500'],
            'success_criteria' => ['sometimes', 'array', 'min:1'],
            'success_criteria.*' => ['string', 'max:500'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
