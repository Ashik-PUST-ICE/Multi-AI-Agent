<?php

namespace App\Services;

use App\Agents\AgentMessageEnvelope;
use App\Agents\AgentRegistry;
use App\Jobs\RunAgentJob;
use App\Llm\UserLlmProviderResolver;
use App\Models\Task;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class TaskModeTransitionService
{
    use ResponseTrait;

    public function __construct(
        private readonly AgentRegistry $agentRegistry,
        private readonly TaskCostEstimator $taskCostEstimator,
        private readonly UserLlmProviderResolver $providerResolver,
        private readonly TaskCostBudgetService $taskCostBudgetService,
    ) {}

    public function transitionToLive(User $user, int $taskId): JsonResponse
    {
        $result = DB::transaction(function () use ($user, $taskId): array {
            $sourceTask = $user->tasks()
                ->with(['agentRuns', 'runEvents'])
                ->lockForUpdate()
                ->findOrFail($taskId);
            Gate::forUser($user)->authorize('transitionToLive', $sourceTask);
            $routing = $sourceTask->input['routing'] ?? [];
            $agentSequence = is_array($routing['agent_sequence'] ?? null)
                ? array_values($routing['agent_sequence'])
                : [$sourceTask->agentRuns->last()?->agent_name ?? ''];
            $taskInput = $sourceTask->input ?? [];
            $prompt = $taskInput['prompt'] ?? $sourceTask->description ?? '';
            $blockedRequirements = $this->blockedRequirements($user, $sourceTask, $agentSequence, $prompt);
            $costEstimate = null;
            $costBudget = null;

            if ($blockedRequirements === []) {
                $costEstimate = $this->taskCostEstimator->estimate(
                    $user,
                    $prompt,
                    $agentSequence,
                    TASK_MODE_LIVE,
                );

                if (($costEstimate['can_complete'] ?? true) === false) {
                    $blockedRequirements[] = 'live_token_budget_insufficient';
                }

                try {
                    $costBudget = $this->taskCostBudgetService->validateSubmission(
                        $user,
                        $costEstimate,
                        isset($taskInput['cost_budget']['task_maximum_cost'])
                            ? (float) $taskInput['cost_budget']['task_maximum_cost']
                            : null,
                    );
                } catch (InvalidArgumentException) {
                    $blockedRequirements[] = 'live_cost_budget_insufficient';
                }
            }

            $transition = $sourceTask->modeTransitions()->create([
                'user_id' => $user->id,
                'from_mode' => $sourceTask->mode,
                'to_mode' => TASK_MODE_LIVE,
                'status' => $blockedRequirements === []
                    ? MODE_TRANSITION_STATUS_APPLIED
                    : MODE_TRANSITION_STATUS_BLOCKED,
                'blocked_requirements' => $blockedRequirements,
                'user_confirmed' => true,
                'transitioned_at' => $blockedRequirements === [] ? now() : null,
            ]);

            $eventSequence = (int) $sourceTask->runEvents()->max('sequence') + 1;
            $sourceTask->runEvents()->create([
                'event_type' => $blockedRequirements === []
                    ? 'task.live_transition_approved'
                    : 'task.live_transition_blocked',
                'mode' => $sourceTask->mode,
                'sequence' => $eventSequence,
                'payload' => [
                    'transition_id' => $transition->id,
                    'requested_by' => $user->id,
                    'blocked_requirements' => $blockedRequirements,
                ],
                'occurred_at' => now(),
            ]);

            if ($blockedRequirements !== []) {
                return [
                    'transition' => $transition,
                    'task' => null,
                    'blocked_requirements' => $blockedRequirements,
                ];
            }

            $taskInput['cost_estimate'] = $costEstimate;
            $taskInput['cost_budget'] = $costBudget;
            $liveTask = $user->tasks()->create([
                'title' => $sourceTask->title,
                'description' => $sourceTask->description,
                'input' => $taskInput,
                'status' => TASK_STATUS_PENDING,
                'mode' => TASK_MODE_LIVE,
            ]);

            $firstAgentName = $agentSequence[0];
            $firstAgentRun = $liveTask->agentRuns()->create([
                'agent_name' => $firstAgentName,
                'mode' => TASK_MODE_LIVE,
                'input' => (new AgentMessageEnvelope(
                    'user',
                    $firstAgentName,
                    $liveTask->id,
                    'task.live_transition',
                    [
                        'prompt' => $prompt,
                        'context' => [],
                    ],
                ))->toArray(),
                'status' => AGENT_RUN_STATUS_PENDING,
            ]);

            $liveTask->runEvents()->create([
                'agent_run_id' => $firstAgentRun->id,
                'event_type' => 'task.mode_transitioned',
                'mode' => TASK_MODE_LIVE,
                'sequence' => 1,
                'payload' => [
                    'source_task_id' => $sourceTask->id,
                    'transition_id' => $transition->id,
                    'from_mode' => TASK_MODE_SANDBOX,
                    'to_mode' => TASK_MODE_LIVE,
                ],
                'occurred_at' => now(),
            ]);

            $transition->update(['resulting_task_id' => $liveTask->id]);
            RunAgentJob::dispatch(
                $firstAgentRun->id,
                $this->agentRegistry->timeoutSeconds($firstAgentName),
            )->afterCommit();

            return [
                'transition' => $transition->fresh(),
                'task' => $liveTask,
                'blocked_requirements' => [],
            ];
        });

        if ($result['blocked_requirements'] !== []) {
            return $this->error(
                'Live mode is unavailable until every required safety gate passes.',
                422,
                [
                    'transition' => [
                        'id' => $result['transition']->id,
                        'status' => 'blocked',
                        'blocked_requirements' => $result['blocked_requirements'],
                    ],
                ],
            );
        }

        /** @var Task $liveTask */
        $liveTask = $result['task'];

        return $this->success([
            'transition' => $result['transition'],
            'task' => $liveTask->only(['id', 'title', 'mode', 'status']),
        ], CREATED_SUCCESSFULLY, 202);
    }

    /**
     * @param  list<string>  $agentSequence
     * @return list<string>
     */
    private function blockedRequirements(User $user, Task $task, array $agentSequence, string $prompt): array
    {
        $blockedRequirements = [];

        if ($task->mode !== TASK_MODE_SANDBOX) {
            $blockedRequirements[] = 'source_task_must_be_sandbox';
        }

        if ($task->status !== TASK_STATUS_COMPLETED) {
            $blockedRequirements[] = 'sandbox_task_must_complete';
        }

        if ($task->agentRuns->contains(
            static fn ($agentRun): bool => $agentRun->status !== AGENT_RUN_STATUS_COMPLETED,
        )) {
            $blockedRequirements[] = 'all_agent_runs_must_complete';
        }

        if (! $task->runEvents()->where('event_type', 'evaluation.passed')->exists()) {
            $blockedRequirements[] = 'evaluation_must_pass';
        }

        if (! $task->runEvents()->where('event_type', 'skeptic.approved')->exists()) {
            $blockedRequirements[] = 'skeptic_report_must_be_good';
        }

        if ($agentSequence === [] || in_array('', $agentSequence, true)) {
            $blockedRequirements[] = 'agent_route_missing';
        }

        foreach ($agentSequence as $agentName) {
            $agentRun = $task->agentRuns->firstWhere('agent_name', $agentName);
            if ($agentRun === null) {
                $blockedRequirements[] = "sandbox_run_missing_for_{$agentName}";

                continue;
            }

            $startedEvent = $task->runEvents->first(
                static fn ($event): bool => $event->agent_run_id === $agentRun->id
                    && $event->event_type === 'agent.started',
            );
            $testedFingerprint = $startedEvent?->payload['routing']['settings_fingerprint'] ?? null;

            try {
                $sandboxSelection = $this->providerResolver->selectedModel(
                    $user,
                    $agentName,
                    TASK_MODE_SANDBOX,
                    null,
                    false,
                    $prompt,
                );
                if (! is_string($testedFingerprint)) {
                    $blockedRequirements[] = "brain_settings_not_tested_for_{$agentName}";
                } elseif (! hash_equals($sandboxSelection['settings_fingerprint'], $testedFingerprint)) {
                    $blockedRequirements[] = "brain_settings_changed_after_sandbox_for_{$agentName}";
                }

                $modelSelection = $this->providerResolver->selectedModel(
                    $user,
                    $agentName,
                    TASK_MODE_LIVE,
                    null,
                    false,
                    $prompt,
                );
                if (! $this->providerResolver->hasConfiguredCredentials($user, $modelSelection['provider'])) {
                    $blockedRequirements[] = "credentials_missing_for_{$agentName}";
                }
            } catch (InvalidArgumentException) {
                $blockedRequirements[] = "approved_model_missing_for_{$agentName}";
            }
        }

        return array_values(array_unique($blockedRequirements));
    }
}
