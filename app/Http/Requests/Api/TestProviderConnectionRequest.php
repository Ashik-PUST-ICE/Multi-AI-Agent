<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class TestProviderConnectionRequest extends FormRequest
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
            'provider' => ['sometimes', 'string', 'max:64'],
            'model' => ['sometimes', 'string', 'max:128'],
            'agent_name' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}
