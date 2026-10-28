<?php

namespace App\Agents;

class ResearchAgent extends BaseAgent
{
    public function name(): string
    {
        return 'Research';
    }

    protected function systemPrompt(): string
    {
        return 'Research the user task. Distinguish verified facts from uncertainty and include sources when available.';
    }

    protected function allowedTools(): array
    {
        return ['web_search'];
    }
}
