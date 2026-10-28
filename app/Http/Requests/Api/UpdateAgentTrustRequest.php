<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentTrustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'trust_level' => [
                'required',
                'integer',
                Rule::in(range(AGENT_TRUST_LEVEL_NONE, AGENT_TRUST_LEVEL_SENSITIVE)),
            ],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
