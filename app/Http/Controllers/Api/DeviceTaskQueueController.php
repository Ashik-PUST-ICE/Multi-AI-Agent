<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceTaskQueueEntry;
use App\Models\User;
use App\Services\ExecutionKillSwitchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceTaskQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->markExpiredForReconfirmation($user);

        return response()->json([
            'queue' => $user->deviceTaskQueueEntries()
                ->with('task:id,title,status')
                ->whereIn('status', ['queued', 'needs_confirmation'])
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (DeviceTaskQueueEntry $entry): array => $this->entryData($entry))
                ->values(),
            'power_controls_enabled' => false,
        ]);
    }

    public function pollDevice(Request $request, int $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken
            || (int) $token->id !== $device
            || (int) $token->tokenable_id !== (int) $user->id
            || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return response()->json(['message' => 'This request must use the matching active device token.'], 403);
        }

        $now = now();
        $entries = DB::transaction(function () use ($device, $now, $user): array {
            $user->deviceTaskQueueEntries()
                ->where('device_token_id', $device)
                ->where('status', 'queued')
                ->where('expires_at', '<=', $now)
                ->update(['status' => 'needs_confirmation', 'updated_at' => $now]);

            $entries = $user->deviceTaskQueueEntries()
                ->with('task:id,title,description,status')
                ->where('device_token_id', $device)
                ->where('status', 'queued')
                ->where('expires_at', '>', $now)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->limit(20)
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                if ($entry->task === null || $entry->task->status !== TASK_STATUS_PENDING) {
                    $entry->update(['status' => 'cancelled']);

                    continue;
                }
                $entry->increment('attempt_count');
                $entry->forceFill(['last_delivered_at' => $now])->save();
            }

            return $entries->filter(fn (DeviceTaskQueueEntry $entry): bool => $entry->fresh()->status === 'queued')->values()->all();
        });

        return response()->json([
            'queue' => array_map(fn (DeviceTaskQueueEntry $entry): array => [
                ...$this->entryData($entry),
                'task' => [
                    'id' => $entry->task->id,
                    'title' => $entry->task->title,
                    'prompt' => $entry->task->description,
                ],
            ], $entries),
            'power_controls_enabled' => false,
            'notice' => 'Queue delivery is read-only. This connector API does not execute tasks or control device power.',
        ]);
    }

    public function reconfirm(Request $request, int $queue): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        $user = $request->user();

        $entry = DB::transaction(function () use ($queue, $user): ?DeviceTaskQueueEntry {
            $entry = $user->deviceTaskQueueEntries()
                ->lockForUpdate()
                ->findOrFail($queue);
            if ($entry->status !== 'needs_confirmation'
                || $entry->task()->where('status', TASK_STATUS_PENDING)->doesntExist()
                || ! $user->tokens()->whereKey($entry->device_token_id)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->exists()) {
                return null;
            }

            $entry->forceFill([
                'status' => 'queued',
                'confirmed_at' => now(),
                'expires_at' => now()->addDay(),
            ])->save();

            $entry->task->runEvents()->create([
                'event_type' => 'device.queue.reconfirmed',
                'mode' => $entry->task->mode,
                'sequence' => (int) $entry->task->runEvents()->max('sequence') + 1,
                'payload' => ['queue_entry_id' => $entry->id, 'expires_at' => $entry->expires_at?->toISOString()],
                'occurred_at' => now(),
            ]);

            return $entry->refresh();
        });

        if ($entry === null) {
            return response()->json([
                'message' => 'This queue item is not awaiting confirmation, its task is no longer pending, or its device token is unavailable.',
            ], 409);
        }

        return response()->json([
            'queue_entry' => $this->entryData($entry),
            'notice' => 'The owner reconfirmed this queued item. It is not automatically executed.',
        ]);
    }

    public function cancel(Request $request, int $queue, ExecutionKillSwitchService $killSwitch): JsonResponse
    {
        $user = $request->user();
        $entry = DB::transaction(function () use ($queue, $user): ?DeviceTaskQueueEntry {
            $entry = $user->deviceTaskQueueEntries()->with('task')->lockForUpdate()->findOrFail($queue);
            if (! in_array($entry->status, ['queued', 'needs_confirmation'], true)) {
                return null;
            }
            $entry->update(['status' => 'cancelled']);

            return $entry;
        });

        if ($entry === null) {
            return response()->json(['message' => 'This queue item is no longer active.'], 409);
        }
        if ($entry->task !== null && in_array($entry->task->status, [TASK_STATUS_PENDING, TASK_STATUS_RUNNING], true)) {
            $killSwitch->stopTask($user, $entry->task, reason: 'Device queue item cancelled by task owner.');
        }

        return response()->json([
            'queue_entry' => $this->entryData($entry->refresh()),
            'notice' => 'The queue item and its pending task were cancelled.',
        ]);
    }

    private function markExpiredForReconfirmation(User $user): void
    {
        $user->deviceTaskQueueEntries()
            ->where('status', 'queued')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'needs_confirmation', 'updated_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entryData(DeviceTaskQueueEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'task_id' => $entry->task_id,
            'device_token_id' => $entry->device_token_id,
            'idempotency_key' => $entry->idempotency_key,
            'status' => $entry->status,
            'priority' => $entry->priority,
            'attempt_count' => $entry->attempt_count,
            'expires_at' => $entry->expires_at?->toISOString(),
            'confirmed_at' => $entry->confirmed_at?->toISOString(),
            'task' => $entry->relationLoaded('task') && $entry->task !== null
                ? [
                    'id' => $entry->task->id,
                    'title' => $entry->task->title,
                    'status' => $entry->task->status,
                ]
                : null,
        ];
    }
}
