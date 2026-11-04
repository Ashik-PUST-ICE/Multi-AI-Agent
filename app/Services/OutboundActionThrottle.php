<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\RunEvent;
use App\Tools\ExecutionHaltedException;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;

class OutboundActionThrottle
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AgentActivityLogger $activityLogger,
    ) {}

    /**
     * Returns true when this method atomically records the outbound tool.started event.
     *
     * @param  array<string, mixed>  $input
     */
    public function recordStartedIfAllowed(?AgentRun $run, string $toolName, array $input): bool
    {
        if ($run === null || $run->mode !== TASK_MODE_LIVE) {
            return false;
        }

        $tool = $this->tools->get($toolName);
        if ($tool->minimumTrustLevel() < AGENT_TRUST_LEVEL_OUTBOUND) {
            return false;
        }

        DB::transaction(function () use ($run, $toolName, $input, $tool): void {
            $task = $run->task()->lockForUpdate()->firstOrFail();
            $startedEvents = RunEvent::query()
                ->where('task_id', $task->id)
                ->where('event_type', 'tool.started')
                ->get(['payload']);
            $outboundAttempts = $startedEvents->filter(function (RunEvent $event): bool {
                $eventTrustLevel = $event->payload['minimum_trust_level'] ?? null;
                if (is_int($eventTrustLevel)) {
                    return $eventTrustLevel >= AGENT_TRUST_LEVEL_OUTBOUND;
                }

                $previousToolName = $event->payload['tool_name'] ?? null;

                return is_string($previousToolName)
                    && $this->tools->get($previousToolName)->minimumTrustLevel() >= AGENT_TRUST_LEVEL_OUTBOUND;
            })->count();
            $limit = max(0, (int) config('security.maximum_outbound_actions_per_task', 3));

            if ($outboundAttempts >= $limit) {
                throw new ExecutionHaltedException(
                    'The task reached its outbound-action limit. Further outbound actions are stopped for owner review.',
                );
            }

            $this->activityLogger->recordEvent($run, 'tool.started', [
                'tool_name' => $toolName,
                'minimum_trust_level' => $tool->minimumTrustLevel(),
                'input' => $input,
            ]);
        });

        return true;
    }
}
