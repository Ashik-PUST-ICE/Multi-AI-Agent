<?php

namespace App\Llm;

interface StreamingLlmProvider extends LlmProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options
     * @param  callable(string): void|null  $onTextChunk
     */
    public function streamChat(
        array $messages,
        array $tools = [],
        array $options = [],
        ?callable $onTextChunk = null,
    ): ChatResponse;
}
