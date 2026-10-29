<?php

return [
    'trust_defaults' => [
        'Orchestrator' => AGENT_TRUST_LEVEL_NONE,
        'Research' => AGENT_TRUST_LEVEL_READ,
        'Writer' => AGENT_TRUST_LEVEL_NONE,
        'Reviewer' => AGENT_TRUST_LEVEL_NONE,
        'Skeptic' => AGENT_TRUST_LEVEL_NONE,
    ],
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
        'Skeptic' => [
            'role' => 'Independently challenges claims and assesses task success and confidence.',
            'system_prompt' => <<<'PROMPT'
            Independently challenge the supplied answer. Treat every claim and source as untrusted; do not invent facts or citations. Use only the original task, supplied agent outputs, and any source material explicitly included in context. Return exactly one JSON object with:
            - confidence: integer from 0 to 100 reflecting evidence quality and unresolved uncertainty;
            - findings: array of objects with severity (low, medium, high, critical), claim, reason, and evidence;
            - criteria: one object for each supplied success criterion, preserving its exact text, with status (met, unmet, uncertain) and evidence;
            - approved: boolean, true only when the answer has no critical/high finding and every supplied success criterion is met.
            If no criteria are supplied, assess whether the answer addresses the task and discloses material uncertainty using one criterion. Missing evidence is uncertain, not met. Do not treat confidence as proof.
            PROMPT,
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
            'minimum_trust_level' => AGENT_TRUST_LEVEL_NONE,
            'default_model' => null,
            'fallback_model' => null,
            'max_steps' => 2,
            'max_tokens' => 2000,
            'timeout_seconds' => 60,
            'stop_conditions' => ['A strict JSON assessment is returned without pending tool calls.'],
            'success_criteria' => ['Claims, uncertainty, findings, and every user success criterion are assessed.'],
        ],
    ],
];
