<?php

namespace App\Services;

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

    /**
     * @param  array{title: string, prompt: string}  $data
     */
    public function submit(User $user, array $data): JsonResponse
    {
        $task = DB::transaction(function () use ($user, $data): Task {
            $task = $user->tasks()->create([
                'title' => $data['title'],
                'description' => $data['prompt'],
                'input' => ['prompt' => $data['prompt']],
                'status' => TASK_STATUS_PENDING,
                'mode' => TASK_MODE_SANDBOX,
            ]);

            $firstAgentRun = $task->agentRuns()->create([
                'agent_name' => self::AGENT_SEQUENCE[0],
                'input' => [
                    'prompt' => $data['prompt'],
                    'context' => [],
                ],
                'status' => AGENT_RUN_STATUS_PENDING,
            ]);

            RunAgentJob::dispatch($firstAgentRun->id)->afterCommit();

            return $task;
        });

        return $this->success(
            ['task' => $this->taskData($task)],
            CREATED_SUCCESSFULLY,
            202,
        );
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

            $nextAgentName = $this->nextAgentName($agentRun->agent_name);

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
            $nextInput = $this->buildAgentInput($task, $nextAgentName, $completedRuns);
            $nextAgentRun = $task->agentRuns()->create([
                'agent_name' => $nextAgentName,
                'input' => $nextInput,
                'status' => AGENT_RUN_STATUS_PENDING,
            ]);

            RunAgentJob::dispatch($nextAgentRun->id)->afterCommit();
        });
    }

    private function nextAgentName(string $currentAgentName): ?string
    {
        $currentIndex = array_search($currentAgentName, self::AGENT_SEQUENCE, true);

        if ($currentIndex === false) {
            throw new InvalidArgumentException("Agent [{$currentAgentName}] is not in the orchestration sequence.");
        }

        $nextIndex = $currentIndex + 1;

        return self::AGENT_SEQUENCE[$nextIndex] ?? null;
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
                'research' => $outputs['Research'] ?? [],
            ],
            'Reviewer' => [
                'original_task' => $prompt,
                'research' => $outputs['Research'] ?? [],
                'draft' => $outputs['Writer'] ?? [],
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

        return [
            'answer' => $agentOutputs['Writer']['text'] ?? '',
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

        if ($includeRuns) {
            $taskData['agent_runs'] = $task->agentRuns->map(static fn (AgentRun $agentRun): array => $agentRun->only([
                'id',
                'agent_name',
                'status',
                'output',
                'error_message',
                'started_at',
                'completed_at',
            ]))->all();
        }

        return $taskData;
    }
}
