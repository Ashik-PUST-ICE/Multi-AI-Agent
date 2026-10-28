<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionAgentSpecStatusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'integer',
                Rule::in([
                    AGENT_SPEC_STATUS_TEST,
                    AGENT_SPEC_STATUS_ACTIVE,
                    AGENT_SPEC_STATUS_RETIRED,
                ]),
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
