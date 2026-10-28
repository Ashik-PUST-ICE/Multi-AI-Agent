<?php

namespace App\Services;

use App\Agents\AgentMessageEnvelope;
use App\Agents\AgentRegistry;
use App\Agents\ComplexityRouter;
use App\Jobs\RunAgentJob;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class OrchestratorService
{
    use ResponseTrait;

    /**
     * @var list<string>
     */
    private const AGENT_SEQUENCE = [
        'Orchestrator',
        'Research',
        'Writer',
        'Reviewer',
    ];

    public function __construct(
        private readonly AgentRegistry $agentRegistry,
        private readonly ComplexityRouter $complexityRouter,
        private readonly TaskCostEstimator $taskCostEstimator,
    ) {}

    /**
     * @param  array{title: string, prompt: string}  $data
     */
    public function submit(User $user, array $data): JsonResponse
    {
        $routing = $this->complexityRouter->route($data['prompt']);
        $costEstimate = $this->taskCostEstimator->estimate(
            $user,
            $data['prompt'],
            $routing['agent_sequence'],
        );

        if (($costEstimate['can_complete'] ?? true) === false) {
            return $this->error(
                'The estimated input leaves no room for an agent response within the configured token budget.',
                422,
            );
        }

        $task = DB::transaction(function () use ($user, $data, $routing, $costEstimate): Task {
            $task = $user->tasks()->create([
                'title' => $data['title'],
                'description' => $data['prompt'],
                'input' => [
                    'prompt' => $data['prompt'],
                    'routing' => $routing,
                    'cost_estimate' => $costEstimate,
                ],
                'status' => TASK_STATUS_PENDING,
                'mode' => TASK_MODE_SANDBOX,
            ]);

            $firstAgentRun = $task->agentRuns()->create([
                'agent_name' => $routing['agent_sequence'][0],
                'input' => (new AgentMessageEnvelope(
                    'user',
                    $routing['agent_sequence'][0],
                    $task->id,
                    'task.created',
                    [
                        'prompt' => $data['prompt'],
                        'context' => [],
                    ],
                ))->toArray(),
                'status' => AGENT_RUN_STATUS_PENDING,
            ]);

            $task->runEvents()->create([
                'agent_run_id' => $firstAgentRun->id,
                'event_type' => 'task.complexity_routed',
                'sequence' => 1,
                'payload' => [
                    ...$routing,
                    'cost_estimate' => $costEstimate,
                ],
                'occurred_at' => now(),
            ]);

            RunAgentJob::dispatch(
                $firstAgentRun->id,
                $this->agentRegistry->timeoutSeconds($firstAgentRun->agent_name),
            )->afterCommit();

            return $task;
        });

        return $this->success(
            [
                'task' => $this->taskData($task),
                'cost_estimate' => $costEstimate,
            ],
            CREATED_SUCCESSFULLY,
            202,
        );
    }

    public function estimate(User $user, string $prompt): JsonResponse
    {
        $routing = $this->complexityRouter->route($prompt);
        $costEstimate = $this->taskCostEstimator->estimate(
            $user,
            $prompt,
            $routing['agent_sequence'],
        );

        return $this->success([
            'routing' => $routing,
            'cost_estimate' => $costEstimate,
        ]);
    }

    public function show(User $user, int $taskId): JsonResponse
    {
        $task = $user->tasks()
            ->with(['agentRuns' => function ($query): void {
                $query->orderBy('id');
            }])
            ->findOrFail($taskId);

        return $this->success(['task' => $this->taskData($task, true)]);
    }

    public function index(User $user): JsonResponse
    {
        $tasks = $user->tasks()
            ->latest('id')
            ->paginate(20);

        return $this->success([
            'tasks' => $tasks->getCollection()
                ->map(fn (Task $task): array => $this->taskData($task))
                ->all(),
            'pagination' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public function completeAgentRun(int $agentRunId, array $output): void
    {
        DB::transaction(function () use ($agentRunId, $output): void {
            $agentRun = AgentRun::with('task')->findOrFail($agentRunId);
            $task = $agentRun->task;

            if ($task === null) {
                throw new RuntimeException('The task for this agent run could not be found.');
            }

            $agentRun->update([
                'status' => AGENT_RUN_STATUS_COMPLETED,
                'output' => $output,
                'completed_at' => now(),
                'error_message' => null,
            ]);

            $nextAgentName = $this->nextAgentName($task, $agentRun->agent_name);

            if ($nextAgentName === null) {
                $completedRuns = $task->agentRuns()->orderBy('id')->get();
                $task->update([
                    'status' => TASK_STATUS_COMPLETED,
                    'result' => $this->buildTaskResult($completedRuns),
                    'completed_at' => now(),
                    'error_message' => null,
                ]);

                return;
            }

            $completedRuns = $task->agentRuns()->orderBy('id')->get();
            $nextPayload = $this->buildAgentInput($task, $nextAgentName, $completedRuns);
            $nextInput = (new AgentMessageEnvelope(
                $agentRun->agent_name,
                $nextAgentName,
                $task->id,
                'agent.handoff',
                $nextPayload,
            ))->toArray();
            $nextAgentRun = $task->agentRuns()->create([
                'agent_name' => $nextAgentName,
                'input' => $nextInput,
                'status' => AGENT_RUN_STATUS_PENDING,
            ]);

            RunAgentJob::dispatch(
                $nextAgentRun->id,
                $this->agentRegistry->timeoutSeconds($nextAgentRun->agent_name),
            )->afterCommit();
        });
    }

    private function nextAgentName(Task $task, string $currentAgentName): ?string
    {
        $agentSequence = $this->agentSequence($task);
        $currentIndex = array_search($currentAgentName, $agentSequence, true);

        if ($currentIndex === false) {
            throw new InvalidArgumentException("Agent [{$currentAgentName}] is not in the task execution route.");
        }

        $nextIndex = $currentIndex + 1;

        return $agentSequence[$nextIndex] ?? null;
    }

    /**
     * @return list<string>
     */
    private function agentSequence(Task $task): array
    {
        $agentSequence = $task->input['routing']['agent_sequence'] ?? self::AGENT_SEQUENCE;

        if (! is_array($agentSequence) || $agentSequence === []) {
            throw new RuntimeException('The task complexity route is invalid.');
        }

        return array_values($agentSequence);
    }

    /**
     * @param  Collection<int, AgentRun>  $completedRuns
     * @return array{prompt: string, context: array<string, mixed>}
     */
    private function buildAgentInput(Task $task, string $agentName, Collection $completedRuns): array
    {
        $prompt = $task->input['prompt'] ?? '';
        $outputs = [];

        foreach ($completedRuns as $completedRun) {
            $outputs[$completedRun->agent_name] = $completedRun->output ?? [];
        }

        $context = match ($agentName) {
            'Research' => [
                'orchestration_plan' => $outputs['Orchestrator']['text'] ?? '',
            ],
            'Writer' => [
                'orchestration_plan' => $outputs['Orchestrator']['text'] ?? '',
                'research' => $outputs['Research']['text'] ?? '',
            ],
            'Reviewer' => [
                'original_task' => $prompt,
                'research' => $outputs['Research']['text'] ?? '',
                'draft' => $outputs['Writer']['text'] ?? '',
            ],
            default => throw new InvalidArgumentException("Agent [{$agentName}] cannot be dispatched by the orchestrator."),
        };

        return [
            'prompt' => $prompt,
            'context' => $context,
        ];
    }

    /**
     * @param  Collection<int, AgentRun>  $completedRuns
     * @return array<string, mixed>
     */
    private function buildTaskResult(Collection $completedRuns): array
    {
        $agentOutputs = [];

        foreach ($completedRuns as $completedRun) {
            $agentOutputs[$completedRun->agent_name] = $completedRun->output ?? [];
        }

        $singleAgentAnswer = $completedRuns->count() === 1
            ? ($completedRuns->first()?->output['text'] ?? '')
            : '';

        return [
            'answer' => $agentOutputs['Writer']['text'] ?? $singleAgentAnswer,
            'plan' => $agentOutputs['Orchestrator']['text'] ?? '',
            'research' => $agentOutputs['Research'] ?? [],
            'review' => $agentOutputs['Reviewer'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskData(Task $task, bool $includeRuns = false): array
    {
        $taskData = $task->only([
            'id',
            'title',
            'description',
            'status',
            'mode',
            'result',
            'error_message',
            'started_at',
            'completed_at',
            'created_at',
        ]);
        $taskInput = $task->input ?? [];
        $taskData['routing'] = $taskInput['routing'] ?? null;
        $taskData['cost_estimate'] = $taskInput['cost_estimate'] ?? null;

        if ($includeRuns) {
            $taskData['agent_runs'] = $task->agentRuns->map(static fn (AgentRun $agentRun): array => $agentRun->only([
                'id',
                'agent_name',
                'status',
                'output',
                'token_usage',
                'error_message',
                'started_at',
                'completed_at',
            ]))->all();
        }

        return $taskData;
    }
}
