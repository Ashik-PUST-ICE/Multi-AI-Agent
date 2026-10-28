<?php

namespace App\Services;

use App\Agents\AgentFactory;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AgentRunService
{
    public function __construct(
        private readonly AgentFactory $agentFactory,
        private readonly OrchestratorService $orchestratorService,
    ) {}

    public function run(int $agentRunId): void
    {
        $agentRun = AgentRun::with(['task.user'])->findOrFail($agentRunId);
        $task = $agentRun->task;

        if ($task === null || in_array($task->status, [TASK_STATUS_COMPLETED, TASK_STATUS_FAILED], true)) {
            return;
        }

        if ($agentRun->status === AGENT_RUN_STATUS_COMPLETED) {
            return;
        }

        $user = $task->user;

        if ($user === null) {
            throw new RuntimeException('The task owner could not be found.');
        }

        $this->markStarted($agentRun);
        $agentInput = $agentRun->input ?? [];
        $prompt = $agentInput['prompt'] ?? null;
        $context = $agentInput['context'] ?? [];

        if (! is_string($prompt) || ! is_array($context)) {
            throw new RuntimeException('The queued agent input is invalid.');
        }

        $agent = $this->agentFactory->make($agentRun->agent_name, $task->mode);
        $output = $agent->runForUser($user, $prompt, $context);

        $this->orchestratorService->completeAgentRun($agentRun->id, $output);
    }

    public function fail(int $agentRunId, Throwable $exception): void
    {
        DB::transaction(function () use ($agentRunId, $exception): void {
            $agentRun = AgentRun::with('task')->find($agentRunId);

            if ($agentRun === null || $agentRun->status === AGENT_RUN_STATUS_COMPLETED) {
                return;
            }

            $agentRun->update([
                'status' => AGENT_RUN_STATUS_FAILED,
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            $task = $agentRun->task;

            if ($task !== null) {
                $task->update([
                    'status' => TASK_STATUS_FAILED,
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);
            }
        });
    }

    private function markStarted(AgentRun $agentRun): void
    {
        DB::transaction(function () use ($agentRun): void {
            $task = $agentRun->task;
            $task->update([
                'status' => TASK_STATUS_RUNNING,
                'started_at' => $task->started_at ?? now(),
            ]);
            $agentRun->update([
                'status' => AGENT_RUN_STATUS_RUNNING,
                'started_at' => $agentRun->started_at ?? now(),
            ]);
        });
    }
}
