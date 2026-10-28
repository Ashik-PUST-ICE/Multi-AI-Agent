<?php

namespace App\Llm;

final class ChatResponse
{
    /**
     * @param  array<int, array{id: string, name: string, arguments: array|string}>  $toolCalls
     * @param  array<string, int|float>  $usage
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls,
        public readonly array $usage,
        public readonly string $stopReason,
    ) {}
}
