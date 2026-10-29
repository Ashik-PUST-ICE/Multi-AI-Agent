<?php

return [
    'schema' => [
        'type' => 'object',
        'required' => [
            'schema_version',
            'language',
            'intent',
            'entities',
            'ambiguity',
            'risk',
            'complexity',
            'success_criteria_suggestions',
            'resources',
            'analysis_method',
            'original_prompt_preserved',
        ],
        'properties' => [
            'schema_version' => ['type' => 'integer', 'enum' => [1]],
            'language' => ['type' => 'string', 'enum' => ['bengali', 'banglish', 'english', 'mixed', 'unknown']],
            'intent' => ['type' => 'string', 'enum' => ['question', 'research', 'writing', 'code', 'data', 'action', 'conversation', 'goal', 'review']],
            'entities' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['type', 'value'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['date', 'file']],
                        'value' => ['type' => 'string'],
                    ],
                ],
            ],
            'ambiguity' => [
                'type' => 'object',
                'required' => ['level', 'questions'],
                'properties' => [
                    'level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                    'questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'risk' => [
                'type' => 'object',
                'required' => ['level', 'reasons', 'never_auto_indicators', 'required_trust_level', 'advisory_only'],
                'properties' => [
                    'level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                    'reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'never_auto_indicators' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'required_trust_level' => ['type' => 'integer'],
                    'advisory_only' => ['type' => 'boolean'],
                ],
            ],
            'complexity' => [
                'type' => 'object',
                'required' => ['level', 'score'],
                'properties' => [
                    'level' => ['type' => 'string', 'enum' => ['simple', 'moderate', 'complex']],
                    'score' => ['type' => 'integer'],
                ],
            ],
            'success_criteria_suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
            'resources' => [
                'type' => 'object',
                'required' => ['laptop_required', 'tools', 'cost_estimate', 'duration_estimate'],
                'properties' => [
                    'laptop_required' => ['type' => 'boolean'],
                    'tools' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'cost_estimate' => ['type' => 'string'],
                    'duration_estimate' => ['type' => 'string'],
                ],
            ],
            'analysis_method' => ['type' => 'string', 'enum' => ['local_rules']],
            'original_prompt_preserved' => ['type' => 'boolean', 'enum' => [true]],
        ],
        'additionalProperties' => false,
    ],
];
