<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUserSkillRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:1000'],
            'instructions' => ['required', 'string', 'min:20', 'max:20000'],
            'visibility' => ['sometimes', 'string', Rule::in(['personal', 'tenant'])],
            'source' => ['sometimes', 'string', Rule::in(['authored', 'external_upload'])],
            'external_install_confirmed' => ['sometimes', 'boolean'],
            'publisher_claim' => ['nullable', 'string', 'max:160'],
            'source_reference' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (($this->input('source') ?? 'authored') === 'external_upload'
                    && ! in_array($this->input('external_install_confirmed'), [true, 1, '1', 'yes', 'on'], true)) {
                    $validator->errors()->add(
                        'external_install_confirmed',
                        'Explicit owner confirmation is required to submit external skill text.',
                    );
                }
            },
        ];
    }
}
