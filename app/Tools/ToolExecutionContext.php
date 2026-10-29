<?php

namespace App\Tools;

use App\Models\AgentRun;
use App\Models\User;

class ToolExecutionContext
{
    public function __construct(
        public readonly User $user,
        public readonly AgentRun $agentRun,
    ) {}
}
