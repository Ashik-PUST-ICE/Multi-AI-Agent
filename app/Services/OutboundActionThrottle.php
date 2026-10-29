<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Tools\ExecutionHaltedException;
use App\Tools\ToolRegistry;

class OutboundActionThrottle
{
    public function __construct(private readonly ToolRegistry $tools) {}

    public function assertAllowed(?AgentRun $run, string $toolName): void
    {
        if ($run === null || $run->mode !== TASK_MODE_LIVE) {
            return;
        }

        $tool = $this->tools->get($toolName);
        if ($tool->minimumTrustLevel() < AGENT_TRUST_LEVEL_OUTBOUND) {
            return;
        }

        $limit = max(0, (int) config('security.maximum_outbound_actions_per_task', 3));
        $task = $run->task;
        $outboundAttempts = $task?->runEvents()
            ->where('event_type', 'tool.started')
            ->get()
            ->filter(function ($event): bool {
                $toolName = $event->payload['tool_name'] ?? null;

                return is_string($toolName)
                    && $this->tools->get($toolName)->minimumTrustLevel() >= AGENT_TRUST_LEVEL_OUTBOUND;
            })
            ->count() ?? 0;

        if ($outboundAttempts >= $limit) {
            throw new ExecutionHaltedException(
                'The task reached its outbound-action limit. Further outbound actions are stopped for owner review.',
            );
        }
    }
}
