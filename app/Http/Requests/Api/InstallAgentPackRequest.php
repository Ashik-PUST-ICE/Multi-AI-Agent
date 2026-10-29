<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class InstallAgentPackRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
        ];
    }
}
