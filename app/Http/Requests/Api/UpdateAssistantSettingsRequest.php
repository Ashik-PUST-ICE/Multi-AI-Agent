<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssistantSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'persona' => ['required', 'string', Rule::in(['friendly', 'concise', 'professional', 'custom'])],
            'custom_persona' => ['nullable', 'string', 'max:500'],
            'voice_input_enabled' => ['required', 'boolean'],
            'voice_output_enabled' => ['required', 'boolean'],
            'voice_language' => ['sometimes', 'string', Rule::in(['bn-BD', 'en-US'])],
            'vision_enabled' => ['required', 'boolean'],
            'smart_home_enabled' => ['required', 'boolean'],
            'proactive_enabled' => ['sometimes', 'boolean'],
            'proactive_max_suggestions' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'proactive_timezone' => ['sometimes', 'string', 'max:64', Rule::in(\DateTimeZone::listIdentifiers())],
        ];
    }
}
