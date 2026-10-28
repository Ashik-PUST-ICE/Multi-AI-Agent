<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SaveProviderApiKeyRequest extends FormRequest
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
            'api_key' => ['nullable', 'string', 'max:8192'],
            'base_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
