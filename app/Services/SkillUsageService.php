<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Models\UserSkill;
use App\Models\UserSkillUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SkillUsageService
{
    public function recordSubmission(Task $task): void
    {
        $matches = $task->input['matched_skills'] ?? [];
        if (! is_array($matches)) {
            return;
        }

        foreach ($matches as $match) {
            if (! is_array($match)
                || ! is_numeric($match['user_skill_id'] ?? null)
                || ! is_numeric($match['user_skill_version_id'] ?? null)) {
                continue;
            }

            UserSkillUsage::query()->firstOrCreate(
                [
                    'user_skill_id' => (int) $match['user_skill_id'],
                    'task_id' => $task->id,
                ],
                [
                    'user_skill_version_id' => (int) $match['user_skill_version_id'],
                    'user_id' => $task->user_id,
                    'tenant_id' => $task->tenant_id,
                    'status' => 'pending',
                    'mode' => $task->mode,
                ],
            );
        }
    }

    public function recordTerminalOutcome(Task $task): void
    {
        if (! in_array($task->status, [TASK_STATUS_COMPLETED, TASK_STATUS_FAILED, TASK_STATUS_CANCELLED], true)) {
            return;
        }

        try {
            DB::transaction(function () use ($task): void {
                $task->unsetRelation('agentRuns')->load('agentRuns');
                $tokenUsage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
                foreach ($task->agentRuns as $agentRun) {
                    $usage = $agentRun->token_usage ?? [];
                    $input = (int) ($usage['input_tokens'] ?? 0);
                    $output = (int) ($usage['output_tokens'] ?? 0);
                    $tokenUsage['input_tokens'] += $input;
                    $tokenUsage['output_tokens'] += $output;
                    $tokenUsage['total_tokens'] += (int) ($usage['total_tokens'] ?? ($input + $output));
                }

                $cost = app(TaskCostBudgetService::class)->summary($task)['spent_by_currency'];
                $durationMs = $task->started_at === null || $task->completed_at === null
                    ? null
                    : max(0, $task->started_at->diffInMilliseconds($task->completed_at));
                $status = match ($task->status) {
                    TASK_STATUS_COMPLETED => 'completed',
                    TASK_STATUS_FAILED => 'failed',
                    default => 'cancelled',
                };
                $usages = UserSkillUsage::query()
                    ->where('task_id', $task->id)
                    ->lockForUpdate()
                    ->get();

                foreach ($usages as $usage) {
                    $usage->update([
                        'status' => $status,
                        'input_tokens' => $tokenUsage['input_tokens'],
                        'output_tokens' => $tokenUsage['output_tokens'],
                        'total_tokens' => $tokenUsage['total_tokens'],
                        'cost_by_currency' => $cost,
                        'duration_ms' => $durationMs,
                        'completed_at' => $task->completed_at ?? now(),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Log::error('Skill usage outcome could not be recorded.', [
                'task_id' => $task->id,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Skill usage analytics could not be recorded for this task.', previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user, int $skillId): array
    {
        $skill = UserSkill::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($skillId);
        $aggregate = $skill->usages()
            ->selectRaw(
                'COUNT(*) as uses, '
                .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed, '
                .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed, '
                .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled, '
                .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending, '
                .'SUM(input_tokens) as input_tokens, '
                .'SUM(output_tokens) as output_tokens, '
                .'SUM(total_tokens) as total_tokens',
                ['completed', 'failed', 'cancelled', 'pending'],
            )
            ->first();
        $completed = (int) ($aggregate->completed ?? 0);
        $failed = (int) ($aggregate->failed ?? 0);
        $settled = $completed + $failed;
        $costByCurrency = [];
        foreach ($skill->usages()->select('cost_by_currency')->cursor() as $usage) {
            foreach ($usage->cost_by_currency ?? [] as $currency => $amount) {
                if (is_string($currency) && is_numeric($amount)) {
                    $costByCurrency[$currency] = ($costByCurrency[$currency] ?? 0.0) + (float) $amount;
                }
            }
        }

        $byVersion = $skill->usages()
            ->join('user_skill_versions', 'user_skill_versions.id', '=', 'user_skill_usages.user_skill_version_id')
            ->selectRaw(
                'user_skill_versions.version as version, '
                .'COUNT(*) as uses, '
                .'SUM(CASE WHEN user_skill_usages.status = ? THEN 1 ELSE 0 END) as completed, '
                .'SUM(CASE WHEN user_skill_usages.status = ? THEN 1 ELSE 0 END) as failed',
                ['completed', 'failed'],
            )
            ->groupBy('user_skill_versions.version')
            ->orderBy('user_skill_versions.version')
            ->get()
            ->map(static fn (object $version): array => [
                'version' => (int) $version->version,
                'uses' => (int) $version->uses,
                'completed' => (int) $version->completed,
                'failed' => (int) $version->failed,
            ])
            ->all();

        return [
            'uses' => (int) ($aggregate->uses ?? 0),
            'completed' => $completed,
            'failed' => $failed,
            'cancelled' => (int) ($aggregate->cancelled ?? 0),
            'pending' => (int) ($aggregate->pending ?? 0),
            'success_rate' => $settled === 0
                ? null
                : round($completed / $settled * 100, 2),
            'token_usage' => [
                'input_tokens' => (int) ($aggregate->input_tokens ?? 0),
                'output_tokens' => (int) ($aggregate->output_tokens ?? 0),
                'total_tokens' => (int) ($aggregate->total_tokens ?? 0),
            ],
            'cost_by_currency' => array_map(
                static fn (float $amount): float => round($amount, 8),
                $costByCurrency,
            ),
            'uses_by_version' => $byVersion,
        ];
    }
}
