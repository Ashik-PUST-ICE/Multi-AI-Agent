<?php

return [
    'default_provider' => env('LLM_DEFAULT_PROVIDER'),
    'default_model' => env('LLM_DEFAULT_MODEL'),
    'providers' => [
        'openai' => [
            'label' => 'OpenAI',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'models' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini'],
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'models' => ['claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022'],
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'models' => ['gemini-2.0-flash', 'gemini-1.5-pro'],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            'models' => ['deepseek-chat', 'deepseek-reasoner'],
        ],
        'openai_compatible' => [
            'label' => 'OpenAI Compatible',
            'base_url' => env('OPENAI_COMPATIBLE_BASE_URL'),
            'models' => explode(',', env('OPENAI_COMPATIBLE_MODELS', '')),
        ],
    ],
    'route_prefix' => 'api/v1',
];
