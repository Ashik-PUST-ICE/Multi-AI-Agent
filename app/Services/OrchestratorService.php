<?php

namespace App\Services;

use App\Agents\AgentMessageEnvelope;
use App\Agents\AgentRegistry;
use App\Agents\ComplexityRouter;
use App\Jobs\RunAgentJob;
use App\Llm\ProviderRequestException;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        private readonly SkepticAssessmentService $skepticAssessmentService,
        private readonly AgentPackService $agentPackService,
        private readonly KnowledgeService $knowledgeService,
    ) {}

    /**
     * @param  array{title: string, prompt: string}  $data
     */
    public function submit(User $user, array $data): JsonResponse
    {
        $routing = $this->complexityRouter->route($data['prompt']);
        $routing['agent_sequence'][] = 'Skeptic';
        $planReview = $data['plan_review'] ?? [
            'required' => false,
            'status' => 'pending',
            'notes' => null,
        ];
        try {
            $agentPack = $this->agentPackService->enabledPack($user, $data['agent_pack'] ?? null);
        } catch (ModelNotFoundException $exception) {
            return $this->error('The requested agent pack is not available.', 422);
        }
        $knowledgeDocumentIds = array_map('intval', $data['knowledge_document_ids'] ?? []);
        $knowledgeContext = $this->knowledgeService->taskPreviewContext(
            $user,
            $data['prompt'],
            $knowledgeDocumentIds,
            $data['knowledge_context_token'] ?? null,
        );
        $estimatedPrompt = $this->promptWithKnowledgeContext($data['prompt'], $knowledgeContext);
        $costEstimate = $this->taskCostEstimator->estimate(
            $user,
            $estimatedPrompt,
            $routing['agent_sequence'],
            TASK_MODE_SANDBOX,
        );
        try {
            $costBudget = app(TaskCostBudgetService::class)->validateSubmission(
                $user,
                $costEstimate,
                isset($data['max_cost']) ? (float) $data['max_cost'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        if (($costEstimate['can_complete'] ?? true) === false) {
            return $this->error(
                'The estimated input leaves no room for an agent response within the configured token budget.',
                422,
            );
        }

        $task = DB::transaction(function () use ($user, $data, $routing, $costEstimate, $planReview, $costBudget, $agentPack, $knowledgeContext): Task {
            $task = $user->tasks()->create([
                'title' => $data['title'],
                'description' => $data['prompt'],
                'input' => [
                    'prompt' => $data['prompt'],
                    'routing' => $routing,
                    'cost_estimate' => $costEstimate,
                    'success_criteria' => $data['success_criteria'] ?? [],
                    'plan_review' => $planReview,
                    'cost_budget' => $costBudget,
                    'agent_pack' => $agentPack,
                    'knowledge_context' => $knowledgeContext,
                    'criteria_retry_count' => 0,
                ],
                'status' => TASK_STATUS_PENDING,
                'mode' => TASK_MODE_SANDBOX,
            ]);

            $firstAgentName = $routing['agent_sequence'][0];
            $firstAgentRun = $task->agentRuns()->create([
                'agent_name' => $firstAgentName,
                'mode' => $task->mode,
                'input' => (new AgentMessageEnvelope(
                    'user',
                    $firstAgentName,
                    $task->id,
                    'task.created',
                    [
                        'prompt' => $data['prompt'],
                        'context' => [
                            'plan_review' => $planReview,
                            'success_criteria' => $data['success_criteria'] ?? [],
                            'agent_pack_guidance' => $agentPack['profiles'][$firstAgentName]['guidance'] ?? null,
                            'knowledge_context' => $knowledgeContext,
                        ],
                    ],
                ))->toArray(),
                'status' => AGENT_RUN_STATUS_PENDING,
            ]);

            $task->runEvents()->create([
                'agent_run_id' => $firstAgentRun->id,
                'event_type' => 'task.complexity_routed',
                'mode' => $task->mode,
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
        $this->knowledgeService->consumeTaskPreview($user, $data['knowledge_context_token'] ?? null);

        return $this->success(
            [
                'task' => $this->taskData($task),
                'cost_estimate' => $costEstimate,
                'cost_budget' => $costBudget,
                'plan_review' => $planReview,
                'agent_pack' => $agentPack,
            ],
            CREATED_SUCCESSFULLY,
            202,
        );
    }

    /**
     * @param  array{prompt: string, max_cost?: numeric-string|float|int|null}  $data
     */
    public function estimate(User $user, array $data): JsonResponse
    {
        $prompt = $data['prompt'];
        try {
            $agentPack = $this->agentPackService->enabledPack($user, $data['agent_pack'] ?? null);
        } catch (ModelNotFoundException $exception) {
            return $this->error('The requested agent pack is not available.', 422);
        }
        try {
            $knowledgePreview = $this->knowledgeService->createTaskPreview(
                $user,
                $prompt,
                array_map('intval', $data['knowledge_document_ids'] ?? []),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (ProviderRequestException $exception) {
            return $this->error('The selected embedding provider could not prepare file context.', 502);
        }
        $knowledgeContext = $knowledgePreview['context'] ?? [];
        $estimatedPrompt = $this->promptWithKnowledgeContext($prompt, $knowledgeContext);
        $routing = $this->complexityRouter->route($prompt);
        $routing['agent_sequence'][] = 'Skeptic';
        $costEstimate = $this->taskCostEstimator->estimate(
            $user,
            $estimatedPrompt,
            $routing['agent_sequence'],
        );
        $autoEstimate = $this->taskCostEstimator->estimate(
            $user,
            $estimatedPrompt,
            $routing['agent_sequence'],
            TASK_MODE_SANDBOX,
            AGENT_BRAIN_MODE_AUTO,
        );
        $fixedEstimate = $this->taskCostEstimator->estimate(
            $user,
            $estimatedPrompt,
            $routing['agent_sequence'],
            TASK_MODE_SANDBOX,
            AGENT_BRAIN_MODE_FIXED,
        );
        try {
            $costBudget = app(TaskCostBudgetService::class)->validateSubmission(
                $user,
                $costEstimate,
                isset($data['max_cost']) ? (float) $data['max_cost'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'routing' => $routing,
            'cost_estimate' => $costEstimate,
            'cost_budget' => $costBudget,
            'agent_pack' => $agentPack,
            'knowledge_preview' => $knowledgePreview === null ? null : [
                'token' => $knowledgePreview['token'],
                'sources' => $knowledgePreview['sources'],
                'expires_in_minutes' => 10,
            ],
            'routing_cost_comparison' => [
                'auto' => $autoEstimate,
                'fixed' => $fixedEstimate,
                'evaluation' => [
                    'status' => 'unavailable',
                    'reason' => 'A trusted evaluation test set and evaluation results are not available yet.',
                ],
            ],
        ]);
    }

    public function show(User $user, int $taskId): JsonResponse
    {
        $task = $user->tasks()
            ->with(['agentRuns' => function ($query): void {
                $query->orderBy('id');
            }])
            ->findOrFail($taskId);
        Gate::forUser($user)->authorize('view', $task);

        $taskData = $this->taskData($task, true);
        $taskData['check_in'] = app(TaskCheckInService::class)->evaluate($task);

        return $this->success(['task' => $taskData]);
    }

    public function replay(User $user, int $taskId): JsonResponse
    {
        $task = $user->tasks()->findOrFail($taskId);
        Gate::forUser($user)->authorize('view', $task);
        $eventModels = $task->runEvents()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $messageIds = $eventModels
            ->map(static fn ($event): mixed => $event->payload['message_id'] ?? null)
            ->filter(static fn (mixed $messageId): bool => is_int($messageId) || ctype_digit((string) $messageId))
            ->unique()
            ->values();
        $messages = $task->messages()
            ->whereIn('id', $messageIds)
            ->get()
            ->keyBy('id');
        $events = $eventModels
            ->map(static function ($event) use ($messages): array {
                $message = $messages->get($event->payload['message_id'] ?? null);

                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'source_mode' => $event->mode,
                    'mode' => TASK_MODE_SANDBOX,
                    'sequence' => $event->sequence,
                    'payload' => $event->payload,
                    'message' => $message?->only(['role', 'content', 'metadata']),
                    'occurred_at' => $event->occurred_at,
                ];
            })
            ->all();

        return $this->success([
            'task_id' => $task->id,
            'replay_mode' => TASK_MODE_SANDBOX,
            'read_only' => true,
            'events' => $events,
            'check_in' => app(TaskCheckInService::class)->evaluate($task),
        ]);
    }

    public function resumeFromRun(User $user, int $taskId, int $agentRunId): JsonResponse
    {
        $sourceTask = $user->tasks()->findOrFail($taskId);
        Gate::forUser($user)->authorize('view', $sourceTask);
        $sourceRun = $sourceTask->agentRuns()->findOrFail($agentRunId);

        if (! in_array($sourceRun->status, [AGENT_RUN_STATUS_COMPLETED, AGENT_RUN_STATUS_FAILED], true)) {
            return $this->error('Only a completed or failed agent step can be restarted.', 409);
        }

        $sourceInput = $sourceRun->input ?? [];
        if (array_key_exists('schema_version', $sourceInput)) {
            $sourceEnvelope = AgentMessageEnvelope::fromArray($sourceInput);
        } else {
            $recordedPrompt = $sourceInput['prompt'] ?? null;
            $recordedContext = $sourceInput['context'] ?? [];
            if (! is_string($recordedPrompt) || ! is_array($recordedContext)) {
                throw new RuntimeException('The recorded agent input cannot be replayed.');
            }

            $sourceEnvelope = new AgentMessageEnvelope(
                'task',
                $sourceRun->agent_name,
                $sourceTask->id,
                'task.input',
                ['prompt' => $recordedPrompt, 'context' => $recordedContext],
            );
        }
        $prompt = $sourceEnvelope->payload['prompt'] ?? null;
        $context = $sourceEnvelope->payload['context'] ?? [];

        if (! is_string($prompt) || ! is_array($context)) {
            throw new RuntimeException('The recorded agent input cannot be replayed.');
        }

        $agentName = $sourceRun->agent_name;
        $routing = [
            'strategy' => 'single_agent',
            'agent_sequence' => [$agentName],
            'reason' => 'Restarted from a recorded step in a new Sandbox task.',
        ];

        $resumedTask = DB::transaction(function () use ($user, $sourceTask, $sourceRun, $sourceEnvelope, $agentName, $prompt, $context, $routing): Task {
            $task = $user->tasks()->create([
                'title' => "Replay: {$sourceTask->title}",
                'description' => $prompt,
                'input' => [
                    'prompt' => $prompt,
                    'routing' => $routing,
                    'replay_source' => [
                        'task_id' => $sourceTask->id,
                        'agent_run_id' => $sourceRun->id,
                    ],
                ],
                'status' => TASK_STATUS_PENDING,
                'mode' => TASK_MODE_SANDBOX,
            ]);
            $agentRun = $task->agentRuns()->create([
                'agent_name' => $agentName,
                'mode' => TASK_MODE_SANDBOX,
                'input' => (new AgentMessageEnvelope(
                    $sourceEnvelope->from,
                    $agentName,
                    $task->id,
                    'task.replay',
                    ['prompt' => $prompt, 'context' => $context],
                ))->toArray(),
                'status' => AGENT_RUN_STATUS_PENDING,
            ]);
            $task->runEvents()->create([
                'agent_run_id' => $agentRun->id,
                'event_type' => 'task.replay_started',
                'mode' => TASK_MODE_SANDBOX,
                'sequence' => 1,
                'payload' => [
                    'source_task_id' => $sourceTask->id,
                    'source_agent_run_id' => $sourceRun->id,
                    'agent_name' => $agentName,
                ],
                'occurred_at' => now(),
            ]);

            RunAgentJob::dispatch(
                $agentRun->id,
                $this->agentRegistry->timeoutSeconds($agentRun->agent_name),
            )->afterCommit();

            return $task;
        });

        return $this->success(
            ['task' => $this->taskData($resumedTask)],
            CREATED_SUCCESSFULLY,
            202,
        );
    }

    public function index(User $user): JsonResponse
    {
        $tasks = $user->tasks()
            ->latest('id')
            ->paginate(20);

        return $this->success([
            'tasks' => $tasks->getCollection()
                ->map(function (Task $task): array {
                    $data = $this->taskData($task);
                    $data['check_in'] = app(TaskCheckInService::class)->evaluate($task);

                    return $data;
                })
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

            $taskCheckIn = app(TaskCheckInService::class)->evaluate($task);
            if ($taskCheckIn['status'] === 'needs_plan_review') {
                $task->update([
                    'status' => TASK_STATUS_PENDING,
                ]);
                $this->recordTaskEvent($agentRun, $task, 'task.plan_review_required', [
                    'status' => 'pending',
                    'reason' => $taskCheckIn['reason'],
                ]);

                return;
            }

            if ($task === null) {
                throw new RuntimeException('The task for this agent run could not be found.');
            }

            $agentRun->update([
                'status' => AGENT_RUN_STATUS_COMPLETED,
                'output' => $output,
                'completed_at' => now(),
                'error_message' => null,
            ]);

            $completedRuns = $task->agentRuns()->orderBy('id')->get();
            if ($agentRun->agent_name === 'Skeptic') {
                $agentOutputs = [];
                foreach ($completedRuns as $completedRun) {
                    $agentOutputs[$completedRun->agent_name] = $completedRun->output ?? [];
                }

                $taskInput = $task->input ?? [];
                $assessment = $this->skepticAssessmentService->assess(
                    (string) ($output['text'] ?? ''),
                    $taskInput['success_criteria'] ?? [],
                    $agentOutputs,
                );
                $taskInput['criteria_assessment'] = $assessment;
                $task->forceFill(['input' => $taskInput])->save();
                $this->recordTaskEvent($agentRun, $task, 'skeptic.assessment', $assessment);
                $this->recordTaskEvent(
                    $agentRun,
                    $task,
                    $assessment['approved'] ? 'skeptic.approved' : 'skeptic.rejected',
                    [
                        'approved' => $assessment['approved'],
                        'criteria_passed' => $assessment['criteria_passed'],
                        'confidence' => $assessment['confidence'],
                        'findings' => $assessment['findings'],
                    ],
                );
                $this->recordTaskEvent(
                    $agentRun,
                    $task,
                    $assessment['criteria_passed'] ? 'evaluation.passed' : 'evaluation.failed',
                    ['checklist' => $assessment['checklist']],
                );

                $retryCount = (int) ($taskInput['criteria_retry_count'] ?? 0);
                if (! $assessment['approved'] && $retryCount < 1) {
                    $routing = $taskInput['routing'] ?? [];
                    $routing['agent_sequence'] = [
                        ...$this->agentSequence($task),
                        'Writer',
                        'Reviewer',
                        'Skeptic',
                    ];
                    $taskInput['routing'] = $routing;
                    $taskInput['criteria_retry_count'] = $retryCount + 1;
                    $task->forceFill(['input' => $taskInput])->save();
                }
            }

            $nextAgentName = $this->nextAgentName($task, $agentRun);

            if ($nextAgentName === null) {
                $completedRuns = $task->agentRuns()->orderBy('id')->get();
                $task->update([
                    'status' => TASK_STATUS_COMPLETED,
                    'result' => $this->buildTaskResult(
                        $completedRuns,
                        $task->input['criteria_assessment'] ?? null,
                    ),
                    'completed_at' => now(),
                    'error_message' => null,
                ]);
                $agentRun->runEvents()->create([
                    'task_id' => $task->id,
                    'event_type' => 'task.completed',
                    'mode' => $task->mode,
                    'sequence' => (int) $agentRun->runEvents()->max('sequence') + 1,
                    'payload' => ['status' => TASK_STATUS_COMPLETED],
                    'occurred_at' => now(),
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
                'mode' => $task->mode,
                'input' => $nextInput,
                'status' => AGENT_RUN_STATUS_PENDING,
            ]);
            $agentRun->runEvents()->create([
                'task_id' => $task->id,
                'event_type' => 'agent.handoff',
                'mode' => $task->mode,
                'sequence' => (int) $agentRun->runEvents()->max('sequence') + 1,
                'payload' => [
                    'from_agent' => $agentRun->agent_name,
                    'to_agent' => $nextAgentName,
                    'next_agent_run_id' => $nextAgentRun->id,
                    'reason' => 'The current stage completed and passed its output to the next routed agent.',
                ],
                'occurred_at' => now(),
            ]);

            RunAgentJob::dispatch(
                $nextAgentRun->id,
                $this->agentRegistry->timeoutSeconds($nextAgentRun->agent_name),
            )->afterCommit();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordTaskEvent(AgentRun $agentRun, Task $task, string $eventType, array $payload): void
    {
        $agentRun->runEvents()->create([
            'task_id' => $task->id,
            'event_type' => $eventType,
            'mode' => $task->mode,
            'sequence' => (int) $agentRun->runEvents()->max('sequence') + 1,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    private function nextAgentName(Task $task, AgentRun $currentRun): ?string
    {
        $agentSequence = $this->agentSequence($task);
        $occurrence = $task->agentRuns()
            ->where('agent_name', $currentRun->agent_name)
            ->where('id', '<', $currentRun->id)
            ->count();
        $matchingIndexes = [];
        foreach ($agentSequence as $index => $agentName) {
            if ($agentName === $currentRun->agent_name) {
                $matchingIndexes[] = $index;
            }
        }
        $currentIndex = $matchingIndexes[$occurrence] ?? false;

        if ($currentIndex === false) {
            throw new InvalidArgumentException("Agent [{$currentRun->agent_name}] is not in the task execution route.");
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
                'success_criteria' => $task->input['success_criteria'] ?? [],
                'skeptic_feedback' => $outputs['Skeptic']['text'] ?? null,
            ],
            'Reviewer' => [
                'original_task' => $prompt,
                'research' => $outputs['Research']['text'] ?? '',
                'draft' => $outputs['Writer']['text'] ?? '',
            ],
            'Skeptic' => [
                'original_task' => $prompt,
                'success_criteria' => $task->input['success_criteria'] ?? [],
                'plan' => $outputs['Orchestrator']['text'] ?? '',
                'research' => $outputs['Research']['text'] ?? '',
                'draft' => $outputs['Writer']['text'] ?? $outputs[$agentName]['text'] ?? '',
                'review' => $outputs['Reviewer']['text'] ?? '',
            ],
            default => throw new InvalidArgumentException("Agent [{$agentName}] cannot be dispatched by the orchestrator."),
        };
        $pack = $task->input['agent_pack'] ?? null;
        if (is_array($pack) && isset($pack['profiles'][$agentName]['guidance'])) {
            $context['agent_pack_guidance'] = $pack['profiles'][$agentName]['guidance'];
        }
        $context['knowledge_context'] = $task->input['knowledge_context'] ?? [];

        return [
            'prompt' => $prompt,
            'context' => $context,
        ];
    }

    /**
     * @param  Collection<int, AgentRun>  $completedRuns
     * @return array<string, mixed>
     */
    private function buildTaskResult(Collection $completedRuns, ?array $assessment = null): array
    {
        $agentOutputs = [];

        foreach ($completedRuns as $completedRun) {
            $agentOutputs[$completedRun->agent_name] = $completedRun->output ?? [];
        }

        $singleAgentAnswer = $completedRuns->count() === 1
            ? ($completedRuns->first()?->output['text'] ?? '')
            : '';

        return [
            'answer' => $agentOutputs['Writer']['text']
                ?? $agentOutputs['Research']['text']
                ?? $agentOutputs['Reviewer']['text']
                ?? $agentOutputs['Orchestrator']['text']
                ?? $singleAgentAnswer,
            'plan' => $agentOutputs['Orchestrator']['text'] ?? '',
            'research' => $agentOutputs['Research'] ?? [],
            'review' => $agentOutputs['Reviewer'] ?? [],
            'skeptic' => $agentOutputs['Skeptic'] ?? [],
            'confidence' => $assessment['confidence'] ?? null,
            'success_criteria' => $assessment['checklist'] ?? [],
            'skeptic_findings' => $assessment['findings'] ?? [],
            'criteria_passed' => $assessment['criteria_passed'] ?? null,
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
        $taskData['cost_budget'] = $taskInput['cost_budget'] ?? null;
        $taskData['agent_pack'] = isset($taskInput['agent_pack'])
            ? [
                'slug' => $taskInput['agent_pack']['slug'],
                'version' => $taskInput['agent_pack']['version'],
                'name' => $taskInput['agent_pack']['name'],
            ]
            : null;
        $taskData['knowledge_sources'] = array_values(array_map(
            static fn (array $item): array => $item['source'],
            $taskInput['knowledge_context'] ?? [],
        ));

        if ($includeRuns) {
            $taskData['cost_summary'] = app(TaskCostBudgetService::class)->summary($task);
            $taskData['agent_runs'] = $task->agentRuns->map(static fn (AgentRun $agentRun): array => $agentRun->only([
                'id',
                'agent_name',
                'status',
                'mode',
                'output',
                'token_usage',
                'error_message',
                'started_at',
                'completed_at',
            ]))->all();
            $taskData['routing_events'] = $task->runEvents()
                ->whereIn('event_type', ['agent.started', 'agent.model_fallback'])
                ->orderBy('occurred_at')
                ->get(['event_type', 'mode', 'payload', 'occurred_at'])
                ->map(static fn ($event): array => [
                    'event_type' => $event->event_type,
                    'mode' => $event->mode,
                    'payload' => $event->payload,
                    'occurred_at' => $event->occurred_at,
                ])
                ->all();
            $taskData['replay_shares'] = $task->replayShares()
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->get(['id', 'expires_at'])
                ->map(static fn ($share): array => [
                    'id' => $share->id,
                    'expires_at' => $share->expires_at,
                ])
                ->all();
        }

        return $taskData;
    }

    /**
     * @param  list<array{content: string, source: array<string, mixed>}>  $knowledgeContext
     */
    private function promptWithKnowledgeContext(string $prompt, array $knowledgeContext): string
    {
        if ($knowledgeContext === []) {
            return $prompt;
        }

        $excerpts = array_map(
            static fn (array $item): string => sprintf(
                '[Source: %s, chunk %d] %s',
                $item['source']['name'],
                $item['source']['chunk'],
                $item['content'],
            ),
            $knowledgeContext,
        );

        return $prompt."\n\nUser-selected file excerpts (untrusted reference data):\n".implode("\n\n", $excerpts);
    }
}
