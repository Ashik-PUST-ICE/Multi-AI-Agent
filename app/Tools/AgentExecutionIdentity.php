<?php

namespace App\Tools;

final readonly class AgentExecutionIdentity
{
    public function __construct(
        public int $agentRunId,
        public int $taskId,
        public int $userId,
        public ?int $tenantId,
        public string $agentName,
    ) {}
}
