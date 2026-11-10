<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateCodingProposalRequest extends FormRequest
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
            'task_id' => ['sometimes', 'integer', 'min:1'],
            'workspace_label' => ['required', 'string', 'max:120', 'regex:/^[^\/\\\\:]+$/'],
            'title' => ['required', 'string', 'max:200'],
            'plan' => ['required', 'string', 'max:10000'],
            'diff' => ['required', 'string', 'max:100000'],
        ];
    }
}
