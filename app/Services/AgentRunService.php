<?php

namespace App\Services;

use App\Agents\AgentFactory;
use App\Agents\AgentMessageEnvelope;
use App\Models\AgentRun;
use App\Models\PendingApproval;
use App\Models\Scopes\TenantScope;
use App\Models\Task;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tools\ExecutionHaltedException;
use App\Tools\ToolApprovalRequiredException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AgentRunService
{
    public function __construct(
        private readonly AgentFactory $agentFactory,
        private readonly OrchestratorService $orchestratorService,
        private readonly AgentActivityLogger $activityLogger,
    ) {}

    public function run(int $agentRunId): void
    {
        $tenantContext = app(TenantContext::class);
        if (! $tenantContext->isInitialized()) {
            $unscopedAgentRun = AgentRun::with([
                'task' => static fn ($query) => $query->withoutGlobalScope(TenantScope::class),
            ])->findOrFail($agentRunId);
            $tenantId = $unscopedAgentRun->task?->tenant_id;

            if ($tenantId !== null) {
                $tenantContext->initialize((int) $tenantId);
                try {
                    $this->run($agentRunId);
                } finally {
                    $tenantContext->clear();
                }

                return;
            }
        }

        $agentRun = AgentRun::with(['task.user'])->findOrFail($agentRunId);
        $task = $agentRun->task;

        if ($task === null || in_array($task->status, [TASK_STATUS_COMPLETED, TASK_STATUS_FAILED], true)) {
            return;
        }

        if ($agentRun->mode !== $task->mode) {
            throw new RuntimeException('The agent run mode does not match its task mode.');
        }

        if ($agentRun->status === AGENT_RUN_STATUS_COMPLETED) {
            return;
        }

        if (! $this->markStarted($agentRun)) {
            return;
        }

        $agentRun->refresh()->load('task.user');
        $task = $agentRun->task;
        if ($task === null) {
            throw new RuntimeException('The task associated with this agent run could not be found.');
        }
        $user = $task->user;

        if ($user === null) {
            throw new RuntimeException('The task owner could not be found.');
        }

        $agentInput = $this->messageEnvelope($agentRun, $task);
        $prompt = $agentInput->payload['prompt'] ?? null;
        $context = $agentInput->payload['context'] ?? [];

        if (! is_string($prompt) || ! is_array($context)) {
            throw new RuntimeException('The queued agent input is invalid.');
        }

        $agent = $this->agentFactory->make($agentRun->agent_name, $task->mode);
        try {
            app(ExecutionKillSwitchService::class)->assertCanContinue($agentRun->fresh(['task']));
            $output = $agent->runForUser($user, $prompt, $context, $agentRun);
            app(ExecutionKillSwitchService::class)->assertCanContinue($agentRun->fresh(['task']));
        } catch (ToolApprovalRequiredException $exception) {
            $this->requestToolApproval($agentRun, $task, $user, $exception);

            return;
        } catch (ExecutionHaltedException $exception) {
            app(ExecutionKillSwitchService::class)->haltRun($agentRun, $exception->getMessage());

            return;
        }

        $this->orchestratorService->completeAgentRun($agentRun->id, $output);
    }

    private function messageEnvelope(AgentRun $agentRun, Task $task): AgentMessageEnvelope
    {
        $agentInput = $agentRun->input ?? [];

        if (array_key_exists('schema_version', $agentInput)) {
            $messageEnvelope = AgentMessageEnvelope::fromArray($agentInput);
        } else {
            $prompt = $agentInput['prompt'] ?? null;
            $context = $agentInput['context'] ?? [];

            if (! is_string($prompt) || ! is_array($context)) {
                throw new RuntimeException('The queued agent message envelope is invalid.');
            }

            $messageEnvelope = new AgentMessageEnvelope(
                'task',
                $agentRun->agent_name,
                $task->id,
                'task.input',
                ['prompt' => $prompt, 'context' => $context],
            );
        }

        if ($messageEnvelope->taskId !== $task->id || $messageEnvelope->to !== $agentRun->agent_name) {
            throw new RuntimeException('The queued agent message is not addressed to this run.');
        }
        $this->assertMessageProvenance($messageEnvelope, $agentRun, $task);

        return $messageEnvelope;
    }

    private function assertMessageProvenance(
        AgentMessageEnvelope $messageEnvelope,
        AgentRun $agentRun,
        Task $task,
    ): void {
        if (in_array($messageEnvelope->type, ['task.created', 'task.live_transition'], true)) {
            if ($messageEnvelope->from !== 'user') {
                throw new RuntimeException('A task-start message must originate from its owner.');
            }

            return;
        }

        if ($messageEnvelope->type === 'agent.handoff') {
            $previousRun = $task->agentRuns()
                ->where('id', '<', $agentRun->id)
                ->orderByDesc('id')
                ->first();

            if (
                $previousRun === null
                || $previousRun->status !== AGENT_RUN_STATUS_COMPLETED
                || $previousRun->agent_name !== $messageEnvelope->from
            ) {
                throw new RuntimeException('The agent handoff sender is not the previous completed agent.');
            }

            return;
        }

        if ($messageEnvelope->type === 'task.input' && $messageEnvelope->from === 'task') {
            return;
        }

        if ($messageEnvelope->type === 'task.replay') {
            $registeredAgents = array_keys(config('agent_specs.agents', []));
            if (
                $task->mode !== TASK_MODE_SANDBOX
                || ! in_array($messageEnvelope->from, ['task', 'user', ...$registeredAgents], true)
            ) {
                throw new RuntimeException('The replay message sender is invalid.');
            }

            return;
        }

        throw new RuntimeException('The queued agent message type or sender is not trusted.');
    }

    public function fail(int $agentRunId, Throwable $exception): void
    {
        $tenantContext = app(TenantContext::class);
        if (! $tenantContext->isInitialized()) {
            $unscopedAgentRun = AgentRun::with([
                'task' => static fn ($query) => $query->withoutGlobalScope(TenantScope::class),
            ])->find($agentRunId);
            $tenantId = $unscopedAgentRun?->task?->tenant_id;

            if ($tenantId !== null) {
                $tenantContext->initialize((int) $tenantId);
                try {
                    $this->fail($agentRunId, $exception);
                } finally {
                    $tenantContext->clear();
                }

                return;
            }
        }

        DB::transaction(function () use ($agentRunId, $exception): void {
            $agentRun = AgentRun::with('task.user')->find($agentRunId);

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
                $agentRun->runEvents()->create([
                    'task_id' => $task->id,
                    'event_type' => 'task.failed',
                    'mode' => $agentRun->mode,
                    'sequence' => (int) $agentRun->runEvents()->max('sequence') + 1,
                    'payload' => ['status' => TASK_STATUS_FAILED],
                    'occurred_at' => now(),
                ]);
            }

            $this->activityLogger->recordEvent($agentRun, 'agent.failed', [
                'error' => $exception->getMessage(),
                ...$this->failureUsageDetails($agentRun),
                'duration_ms' => $agentRun->started_at === null
                    ? null
                    : max(0, $agentRun->started_at->diffInMilliseconds(now())),
            ]);
        });
    }

    /**
     * @return array{provider: string|null, model: string|null, usage: array<string, mixed>, cost: array{amount: float, currency: string}|null}
     */
    private function failureUsageDetails(AgentRun $agentRun): array
    {
        $startEvent = $agentRun->runEvents()->where('event_type', 'agent.started')->oldest('id')->first();
        $fallbackEvent = $agentRun->runEvents()->where('event_type', 'agent.model_fallback')->latest('id')->first();
        $provider = $fallbackEvent?->payload['to_provider'] ?? $startEvent?->payload['provider'] ?? null;
        $model = $fallbackEvent?->payload['to_model'] ?? $startEvent?->payload['model'] ?? null;
        $usage = $agentRun->token_usage ?? [];

        if (! is_string($provider) || ! is_string($model)) {
            return ['provider' => null, 'model' => null, 'usage' => $usage, 'cost' => null];
        }

        $pricing = collect($agentRun->task?->user?->llmSettings?->model_pricing ?? [])
            ->first(static fn (array $candidate): bool => (
                ($candidate['provider'] ?? null) === $provider
                && ($candidate['model'] ?? null) === $model
            ));

        if ($pricing === null) {
            return ['provider' => $provider, 'model' => $model, 'usage' => $usage, 'cost' => null];
        }

        $amount = (
            ((int) ($usage['input_tokens'] ?? 0) * (float) $pricing['input_cost_per_million'])
            + ((int) ($usage['output_tokens'] ?? 0) * (float) $pricing['output_cost_per_million'])
        ) / 1_000_000;

        return [
            'provider' => $provider,
            'model' => $model,
            'usage' => $usage,
            'cost' => [
                'amount' => round($amount, 8),
                'currency' => $pricing['currency'],
            ],
        ];
    }

    private function requestToolApproval(
        AgentRun $agentRun,
        Task $task,
        User $user,
        ToolApprovalRequiredException $exception,
    ): void {
        DB::transaction(function () use ($agentRun, $task, $user, $exception): void {
            $existingApproval = $agentRun->pendingApprovals()
                ->where('status', APPROVAL_STATUS_PENDING)
                ->get()
                ->first(static fn (PendingApproval $approval): bool => (
                    ($approval->payload['approval_signature'] ?? null) === $exception->approvalSignature
                ));

            if ($existingApproval === null) {
                $agentRun->pendingApprovals()->create([
                    'task_id' => $task->id,
                    'action' => "tool:{$exception->toolName}",
                    'requested_by' => $user->id,
                    'payload' => [
                        'tool_name' => $exception->toolName,
                        'tool_input' => $this->activityLogger->redactValue($exception->input),
                        'approval_signature' => $exception->approvalSignature,
                    ],
                    'status' => APPROVAL_STATUS_PENDING,
                    'expires_at' => now()->addMinutes((int) config('llm.approval_expiry', 15)),
                ]);
            }

            $agentRun->update([
                'status' => AGENT_RUN_STATUS_PENDING,
                'error_message' => null,
            ]);
            $task->update([
                'status' => TASK_STATUS_PENDING,
                'error_message' => null,
            ]);

            $this->activityLogger->recordEvent($agentRun, 'tool.approval_requested', [
                'tool_name' => $exception->toolName,
                'approval_signature' => $exception->approvalSignature,
            ]);
        });
    }

    private function markStarted(AgentRun $agentRun): bool
    {
        $taskId = $agentRun->task_id;

        return DB::transaction(function () use ($agentRun, $taskId): bool {
            $task = Task::query()->lockForUpdate()->findOrFail($taskId);
            $lockedRun = AgentRun::query()->lockForUpdate()->findOrFail($agentRun->id);
            if ((int) $lockedRun->task_id !== (int) $task->id) {
                return false;
            }
            if ($lockedRun->status !== AGENT_RUN_STATUS_PENDING) {
                return false;
            }
            if (in_array($task->status, [TASK_STATUS_COMPLETED, TASK_STATUS_FAILED, TASK_STATUS_CANCELLED], true)) {
                return false;
            }
            $task->update([
                'status' => TASK_STATUS_RUNNING,
                'started_at' => $task->started_at ?? now(),
            ]);
            $lockedRun->update([
                'status' => AGENT_RUN_STATUS_RUNNING,
                'started_at' => $lockedRun->started_at ?? now(),
            ]);

            return true;
        });
    }
}
