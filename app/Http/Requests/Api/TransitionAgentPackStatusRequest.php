<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class TransitionAgentPackStatusRequest extends FormRequest
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
            'status' => [
                'required',
                'integer',
                'in:'.AGENT_PACK_STATUS_ACTIVE.','.AGENT_PACK_STATUS_RETIRED,
            ],
        ];
    }
}
