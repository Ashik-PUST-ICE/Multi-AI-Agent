<?php

namespace App\Tools;

use App\Models\AgentRun;
use App\Models\User;
use InvalidArgumentException;

class ToolExecutionContext
{
    public readonly AgentExecutionIdentity $identity;

    public function __construct(
        public readonly User $user,
        public readonly AgentRun $agentRun,
    ) {
        $task = $agentRun->task;
        if (
            $task === null
            || (int) $task->id !== (int) $agentRun->task_id
            || (int) $task->user_id !== (int) $user->id
            || ($task->tenant_id === null ? null : (int) $task->tenant_id)
                !== ($user->tenant_id === null ? null : (int) $user->tenant_id)
        ) {
            throw new InvalidArgumentException('Tool execution identity does not match its task owner and tenant.');
        }

        $this->identity = new AgentExecutionIdentity(
            (int) $agentRun->id,
            (int) $task->id,
            (int) $user->id,
            $user->tenant_id === null ? null : (int) $user->tenant_id,
            $agentRun->agent_name,
        );
    }
}
