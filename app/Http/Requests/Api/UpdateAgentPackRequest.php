<?php

namespace App\Http\Requests\Api;

class UpdateAgentPackRequest extends CreateAgentPackRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'required', 'string', 'max:1000'],
            'agents' => ['sometimes', 'required', 'array', 'min:1', 'max:4'],
            'agents.*' => ['required', 'string', 'distinct', 'in:Orchestrator,Research,Writer,Reviewer'],
            'guidance' => ['sometimes', 'array', 'max:4'],
            'guidance.*' => ['required', 'string', 'max:4000'],
            'examples' => ['sometimes', 'required', 'array', 'min:1', 'max:10'],
            'examples.*' => ['required', 'string', 'max:500'],
        ];
    }
}
