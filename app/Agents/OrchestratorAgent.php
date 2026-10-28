<?php

namespace App\Agents;

class OrchestratorAgent extends BaseAgent
{
    public function name(): string
    {
        return 'Orchestrator';
    }

    protected function systemPrompt(): string
    {
        return 'Create a concise, safe plan for the user task. Do not execute tools.';
    }
}
