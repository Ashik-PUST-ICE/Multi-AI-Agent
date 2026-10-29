<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskReplayShare;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskReplayShareService
{
    public function create(User $user, int $taskId, int $expiresInHours): array
    {
        $task = $user->tasks()->findOrFail($taskId);
        $token = Str::random(64);

        $share = DB::transaction(function () use ($user, $task, $token, $expiresInHours): TaskReplayShare {
            $task->replayShares()
                ->whereNull('revoked_at')
                ->where('expires_at', '<=', now())
                ->update(['revoked_at' => now()]);

            return $task->replayShares()->create([
                'created_by' => $user->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours($expiresInHours),
            ]);
        });

        return [
            'id' => $share->id,
            'url' => route('api.v1.task-replays.show', ['token' => $token]),
            'expires_at' => $share->expires_at,
        ];
    }

    public function revoke(User $user, int $taskId, int $shareId): void
    {
        $task = $user->tasks()->findOrFail($taskId);
        $share = $task->replayShares()->findOrFail($shareId);
        $share->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * @return array{task: array<string, mixed>, events: array<int, array<string, mixed>>}
     */
    public function sharedReplay(string $token): array
    {
        $share = TaskReplayShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->with('task')
            ->first();

        if ($share === null || $share->task === null) {
            throw (new ModelNotFoundException)->setModel(TaskReplayShare::class);
        }

        $task = $share->task;

        return [
            'task' => [
                'title' => $task->title,
                'status' => $task->status,
                'mode' => TASK_MODE_SANDBOX,
                'created_at' => $task->created_at,
            ],
            'events' => $task->runEvents()
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get(['event_type', 'sequence', 'payload', 'occurred_at'])
                ->map(static fn ($event): array => [
                    'event_type' => $event->event_type,
                    'sequence' => $event->sequence,
                    'occurred_at' => $event->occurred_at,
                    'details' => self::shareableDetails($event->event_type, $event->payload ?? []),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function shareableDetails(string $eventType, array $payload): array
    {
        return match ($eventType) {
            'task.complexity_routed' => array_filter([
                'strategy' => $payload['strategy'] ?? null,
                'agent_sequence' => $payload['agent_sequence'] ?? null,
                'reason' => $payload['reason'] ?? null,
                'cost_estimate' => $payload['cost_estimate'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'agent.started', 'agent.completed' => array_filter([
                'agent' => $payload['agent'] ?? null,
                'provider' => $payload['provider'] ?? null,
                'model' => $payload['model'] ?? null,
                'usage' => $eventType === 'agent.completed' ? ($payload['usage'] ?? null) : null,
                'duration_ms' => $payload['duration_ms'] ?? null,
                'cost' => $payload['cost'] ?? null,
                'reason' => $payload['routing']['reason'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'agent.model_fallback' => array_filter([
                'from_provider' => $payload['from_provider'] ?? null,
                'from_model' => $payload['from_model'] ?? null,
                'to_provider' => $payload['to_provider'] ?? null,
                'to_model' => $payload['to_model'] ?? null,
                'reason' => $payload['reason'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'agent.handoff' => array_filter([
                'from_agent' => $payload['from_agent'] ?? null,
                'to_agent' => $payload['to_agent'] ?? null,
                'reason' => $payload['reason'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'agent.failed' => array_filter([
                'provider' => $payload['provider'] ?? null,
                'model' => $payload['model'] ?? null,
                'usage' => $payload['usage'] ?? null,
                'duration_ms' => $payload['duration_ms'] ?? null,
                'cost' => $payload['cost'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'task.completed', 'task.failed' => [
                'status' => $payload['status'] ?? null,
            ],
            'tool.completed' => array_filter([
                'tool_name' => $payload['tool_name'] ?? null,
                'duration_ms' => $payload['duration_ms'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'tool.failed' => array_filter([
                'tool_name' => $payload['tool_name'] ?? null,
                'duration_ms' => $payload['duration_ms'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            default => [],
        };
    }
}
