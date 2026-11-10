<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserAssistantSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProactiveBriefingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->assistantSettings()->first();
        if ($settings === null || ! $settings->proactive_enabled) {
            return response()->json([
                'enabled' => false,
                'suggestions' => [],
                'message' => 'Daily briefing is disabled in Assistant settings.',
            ]);
        }

        $timezone = $settings->proactive_timezone ?: 'UTC';
        $today = now($timezone)->toDateString();
        $settings = DB::transaction(function () use ($settings, $today, $user): UserAssistantSetting {
            $lockedSettings = UserAssistantSetting::query()
                ->whereKey($settings->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedSettings->proactive_briefing_date?->format('Y-m-d') !== $today) {
                $lockedSettings->proactive_briefing = $user->tasks()
                    ->whereIn('status', [TASK_STATUS_PENDING, TASK_STATUS_RUNNING])
                    ->orderBy('updated_at')
                    ->limit($lockedSettings->proactive_max_suggestions)
                    ->get(['id', 'title', 'status', 'updated_at'])
                    ->map(fn ($task): array => [
                        'task_id' => $task->id,
                        'title' => $task->title,
                        'status' => $task->status === TASK_STATUS_RUNNING ? 'in_progress' : 'pending',
                        'reason' => 'This task is not yet complete.',
                    ])
                    ->values()
                    ->all();
                $lockedSettings->proactive_briefing_date = $today;
                $lockedSettings->save();
            }

            return $lockedSettings;
        });
        $briefing = $settings->proactive_briefing ?? [];
        $taskIds = array_column($briefing, 'task_id');
        $activeTaskIds = $user->tasks()
            ->whereIn('status', [TASK_STATUS_PENDING, TASK_STATUS_RUNNING])
            ->whereIn('id', $taskIds)
            ->pluck('id')
            ->map(static fn ($taskId): int => (int) $taskId)
            ->all();
        $briefing = array_values(array_filter(
            $briefing,
            fn (array $suggestion): bool => in_array((int) $suggestion['task_id'], $activeTaskIds, true),
        ));

        return response()->json([
            'enabled' => true,
            'date' => $today,
            'timezone' => $timezone,
            'suggestion_limit' => $settings->proactive_max_suggestions,
            'suggestions' => array_slice($briefing, 0, $settings->proactive_max_suggestions),
            'notice' => 'Suggestions are informational only. No task is started or approved automatically.',
        ]);
    }
}
