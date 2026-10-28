<?php

return [
    'default_provider' => env('LLM_DEFAULT_PROVIDER'),
    'default_model' => env('LLM_DEFAULT_MODEL'),
    'connect_timeout' => (int) env('LLM_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('LLM_TIMEOUT', 30),
    'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),
];
