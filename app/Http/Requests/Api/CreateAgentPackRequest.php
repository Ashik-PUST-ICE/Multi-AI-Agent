<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateAgentPackRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:1000'],
            'agents' => ['required', 'array', 'min:1', 'max:4'],
            'agents.*' => ['required', 'string', 'distinct', 'in:Orchestrator,Research,Writer,Reviewer'],
            'guidance' => ['sometimes', 'array', 'max:4'],
            'guidance.*' => ['required', 'string', 'max:4000'],
            'examples' => ['required', 'array', 'min:1', 'max:10'],
            'examples.*' => ['required', 'string', 'max:500'],
        ];
    }
}
