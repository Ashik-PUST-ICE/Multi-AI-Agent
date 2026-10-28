<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderSettingsRequest extends FormRequest
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
            'provider' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:128'],
            'approved_models' => ['required', 'array', 'min:1', 'max:100'],
            'approved_models.*.provider' => ['required', 'string', 'max:64'],
            'approved_models.*.model' => ['required', 'string', 'max:128'],
            'agent_models' => ['sometimes', 'array', 'max:20'],
            'agent_models.*.provider' => ['required', 'string', 'max:64'],
            'agent_models.*.model' => ['required', 'string', 'max:128'],
            'fallback_provider' => ['nullable', 'required_with:fallback_model', 'string', 'max:64'],
            'fallback_model' => ['nullable', 'required_with:fallback_provider', 'string', 'max:128'],
            'model_pricing' => ['sometimes', 'array', 'max:100'],
            'model_pricing.*.provider' => ['required', 'string', 'max:64'],
            'model_pricing.*.model' => ['required', 'string', 'max:128'],
            'model_pricing.*.currency' => ['required', 'string', 'size:3', 'alpha'],
            'model_pricing.*.input_cost_per_million' => ['required', 'numeric', 'min:0', 'max:100000'],
            'model_pricing.*.output_cost_per_million' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
