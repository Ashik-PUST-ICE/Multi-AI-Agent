<?php

return [
    'agents' => [
        'Orchestrator' => [
            'role' => 'Creates a concise and safe plan for the requested task.',
            'system_prompt' => 'Create a concise, safe plan for the user task. Do not execute tools.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['prompt', 'context'],
                'properties' => [
                    'prompt' => ['type' => 'string', 'minLength' => 1],
                    'context' => ['type' => 'object'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['text'],
                'properties' => [
                    'text' => ['type' => 'string', 'minLength' => 1],
                ],
            ],
            'allowed_tools' => [],
            'minimum_trust_level' => 0,
            'default_model' => null,
            'fallback_model' => null,
            'max_steps' => 5,
            'max_tokens' => 2000,
            'timeout_seconds' => 60,
            'stop_conditions' => ['A final response is returned without pending tool calls.'],
            'success_criteria' => ['The output contains a safe, concise plan.'],
        ],
        'Research' => [
            'role' => 'Researches the task and separates verified facts from uncertainty.',
            'system_prompt' => 'Research the user task. Distinguish verified facts from uncertainty and include sources when available.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['prompt', 'context'],
                'properties' => [
                    'prompt' => ['type' => 'string', 'minLength' => 1],
                    'context' => ['type' => 'object'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['text'],
                'properties' => [
                    'text' => ['type' => 'string', 'minLength' => 1],
                ],
            ],
            'allowed_tools' => ['web_search'],
            'minimum_trust_level' => 1,
            'default_model' => null,
            'fallback_model' => null,
            'max_steps' => 5,
            'max_tokens' => 3000,
            'timeout_seconds' => 60,
            'stop_conditions' => ['A final response is returned without pending tool calls.'],
            'success_criteria' => ['Claims are distinguished from uncertainty and sources are included when available.'],
        ],
        'Writer' => [
            'role' => 'Writes an accurate final response from the task and supplied context.',
            'system_prompt' => 'Write a clear, accurate response using only the supplied information. Do not claim actions were taken unless confirmed.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['prompt', 'context'],
                'properties' => [
                    'prompt' => ['type' => 'string', 'minLength' => 1],
                    'context' => ['type' => 'object'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['text'],
                'properties' => [
                    'text' => ['type' => 'string', 'minLength' => 1],
                ],
            ],
            'allowed_tools' => [],
            'minimum_trust_level' => 2,
            'default_model' => null,
            'fallback_model' => null,
            'max_steps' => 5,
            'max_tokens' => 3000,
            'timeout_seconds' => 60,
            'stop_conditions' => ['A final response is returned without pending tool calls.'],
            'success_criteria' => ['The response answers the user task using only supplied information.'],
        ],
        'Reviewer' => [
            'role' => 'Checks the supplied work against the original request.',
            'system_prompt' => 'Review the supplied work against the user request. Identify gaps and report whether it meets the stated criteria.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['prompt', 'context'],
                'properties' => [
                    'prompt' => ['type' => 'string', 'minLength' => 1],
                    'context' => ['type' => 'object'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['text'],
                'properties' => [
                    'text' => ['type' => 'string', 'minLength' => 1],
                ],
            ],
            'allowed_tools' => [],
            'minimum_trust_level' => 0,
            'default_model' => null,
            'fallback_model' => null,
            'max_steps' => 5,
            'max_tokens' => 2000,
            'timeout_seconds' => 60,
            'stop_conditions' => ['A final response is returned without pending tool calls.'],
            'success_criteria' => ['The review identifies material gaps against the user request.'],
        ],
    ],
];
