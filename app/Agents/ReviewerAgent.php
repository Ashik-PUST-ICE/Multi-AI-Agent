<?php

namespace App\Agents;

class ReviewerAgent extends BaseAgent
{
    public function name(): string
    {
        return 'Reviewer';
    }

    protected function systemPrompt(): string
    {
        return 'Review the supplied work against the user request. Identify gaps and report whether it meets the stated criteria.';
    }
}
