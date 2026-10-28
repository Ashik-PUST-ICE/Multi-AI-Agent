<?php

return [
    'default_provider' => env('LLM_DEFAULT_PROVIDER'),
    'default_model' => env('LLM_DEFAULT_MODEL'),
    'connect_timeout' => (int) env('LLM_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('LLM_TIMEOUT', 30),
    'agent_timeout' => (int) env('LLM_AGENT_TIMEOUT', 60),
    'max_tool_calls' => (int) env('LLM_MAX_TOOL_CALLS', 10),
    'approval_expiry' => (int) env('LLM_APPROVAL_EXPIRY_MINUTES', 15),
    'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),
    'task_token_budget' => (int) env('LLM_TASK_TOKEN_BUDGET', 20000),
    'estimated_characters_per_token' => (int) env('LLM_ESTIMATED_CHARACTERS_PER_TOKEN', 3),
    'estimated_context_tokens_per_agent' => (int) env('LLM_ESTIMATED_CONTEXT_TOKENS_PER_AGENT', 256),
];
