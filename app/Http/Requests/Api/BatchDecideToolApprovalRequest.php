<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class BatchDecideToolApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|In>>
     */
    public function rules(): array
    {
        return [
            'approval_ids' => ['required', 'array', 'min:2', 'max:25'],
            'approval_ids.*' => ['required', 'integer', 'distinct'],
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
