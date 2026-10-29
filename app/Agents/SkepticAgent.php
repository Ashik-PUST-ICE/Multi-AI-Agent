<?php

namespace App\Agents;

class SkepticAgent extends BaseAgent
{
    public function name(): string
    {
        return 'Skeptic';
    }

    protected function systemPrompt(): string
    {
        return 'Independently assess the answer, success criteria, evidence, and uncertainty. Return a strict JSON assessment.';
    }
}
