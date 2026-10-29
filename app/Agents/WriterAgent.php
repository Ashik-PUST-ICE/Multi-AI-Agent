<?php

namespace App\Agents;

class WriterAgent extends BaseAgent
{
    public function name(): string
    {
        return 'Writer';
    }

    protected function systemPrompt(): string
    {
        return 'Write a clear, accurate response using only the supplied information. Do not claim actions were taken unless confirmed.';
    }

    protected function allowedTools(): array
    {
        return ['send_message'];
    }
}
