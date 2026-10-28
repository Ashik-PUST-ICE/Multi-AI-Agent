<?php

namespace App\Llm;

interface LlmProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $tools = [], array $options = []): ChatResponse;
}
