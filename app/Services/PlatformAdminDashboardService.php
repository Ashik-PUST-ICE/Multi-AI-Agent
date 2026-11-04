<?php

namespace App\Services;

use App\Models\AgentSpec;
use App\Models\EvaluationResult;
use App\Models\RunEvent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlatformAdminDashboardService
{
    public function __construct(private readonly TaskCostBudgetService $costBudgetService) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $now = now();
        $tasks = Task::withoutGlobalScopes()->whereNull('deleted_at');
        $recentTasks = (clone $tasks)->where('created_at', '>=', $now->copy()->subDay());
        $recentFailures = (clone $tasks)
            ->where('status', TASK_STATUS_FAILED)
            ->where('updated_at', '>=', $now->copy()->subDay())
            ->count();
        $failedJobs = 0;
        if (Schema::hasTable('failed_jobs')) {
            $failedJobsQuery = DB::table('failed_jobs');
            if (Schema::hasColumn('failed_jobs', 'failed_at')) {
                $failedJobsQuery->where('failed_at', '>=', $now->copy()->subDay());
            }
            $failedJobs = $failedJobsQuery->count();
        }
        $usage = $this->usageSummary($now->copy()->subDays(30));
        $alerts = [];

        if ($recentFailures > 0 || $failedJobs > 0) {
            $alerts[] = [
                'type' => 'failed_jobs',
                'severity' => 'warning',
                'message' => 'Recent execution failures need review.',
                'failed_tasks_24h' => $recentFailures,
                'failed_queue_jobs' => $failedJobs,
            ];
        }

        $budgetAlerts = $this->budgetAlerts($now->copy()->subDay());
        if ($budgetAlerts !== []) {
            $alerts[] = [
                'type' => 'budget',
                'severity' => 'warning',
                'message' => 'One or more task cost limits have reached at least 80%.',
                'tasks' => $budgetAlerts,
            ];
        }

        $costSpikes = $this->costSpikes($usage['daily_spend']);
        foreach ($costSpikes as $spike) {
            $alerts[] = [
                'type' => 'abnormal_cost',
                'severity' => 'warning',
                'message' => 'Today’s estimated spend is more than twice the recent daily average.',
                ...$spike,
            ];
        }

        return [
            'summary' => [
                'users' => User::query()->count(),
                'tasks' => (clone $tasks)->count(),
                'completed_tasks_24h' => (clone $recentTasks)->where('status', TASK_STATUS_COMPLETED)->count(),
                'failed_tasks_24h' => $recentFailures,
                'estimated_spend_30d' => $usage['spend_30d'],
                'llm_calls_30d' => $usage['calls_30d'],
            ],
            'active_agent_specs' => AgentSpec::query()
                ->where('status', AGENT_SPEC_STATUS_ACTIVE)
                ->orderBy('agent_name')
                ->get(['agent_name', 'version', 'updated_at']),
            'recent_users' => User::query()
                ->latest('id')
                ->limit(20)
                ->get(['id', 'name', 'email', 'status', 'last_seen', 'created_at']),
            'alerts' => $alerts,
        ];
    }

    /**
     * @return array{spend_30d: array<string, float>, calls_30d: int, daily_spend: array<string, array<string, float>>}
     */
    private function usageSummary(Carbon $since): array
    {
        $spend = [];
        $dailySpend = [];
        $calls = 0;
        RunEvent::query()
            ->where('event_type', 'agent.llm_call')
            ->where('occurred_at', '>=', $since)
            ->orderBy('id')
            ->chunkById(1000, function ($events) use (&$spend, &$dailySpend, &$calls): void {
                foreach ($events as $event) {
                    $calls++;
                    $payload = $event->payload ?? [];
                    $cost = $payload['cost'] ?? null;
                    if (! is_array($cost)
                        || ! is_numeric($cost['amount'] ?? null)
                        || ! is_string($cost['currency'] ?? null)) {
                        continue;
                    }

                    $currency = $cost['currency'];
                    $amount = (float) $cost['amount'];
                    $day = $event->occurred_at?->toDateString();
                    $spend[$currency] = ($spend[$currency] ?? 0.0) + $amount;
                    if ($day !== null) {
                        $dailySpend[$day][$currency] = ($dailySpend[$day][$currency] ?? 0.0) + $amount;
                    }
                }
            });
        EvaluationResult::withoutGlobalScopes()
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->chunkById(1000, function ($results) use (&$spend, &$dailySpend, &$calls): void {
                foreach ($results as $result) {
                    $calls++;
                    if (! is_numeric($result->cost_amount) || ! is_string($result->cost_currency)) {
                        continue;
                    }

                    $currency = $result->cost_currency;
                    $amount = (float) $result->cost_amount;
                    $spend[$currency] = ($spend[$currency] ?? 0.0) + $amount;
                    $day = $result->created_at?->toDateString();
                    if ($day !== null) {
                        $dailySpend[$day][$currency] = ($dailySpend[$day][$currency] ?? 0.0) + $amount;
                    }
                }
            });

        return [
            'spend_30d' => array_map(static fn (float $amount): float => round($amount, 8), $spend),
            'calls_30d' => $calls,
            'daily_spend' => $dailySpend,
        ];
    }

    /**
     * @return list<array{task_id: int, spend: float, limit: float, currency: string}>
     */
    private function budgetAlerts(Carbon $since): array
    {
        $tasks = Task::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->limit(100)
            ->get(['id', 'input']);
        $alerts = [];

        foreach ($tasks as $task) {
            $budget = ($task->input ?? [])['cost_budget'] ?? null;
            if (! is_array($budget) || ! is_numeric($budget['maximum_cost'] ?? null)) {
                continue;
            }

            $summary = $this->costBudgetService->summary($task);
            $currency = $budget['currency'] ?? null;
            $limit = (float) $budget['maximum_cost'];
            $spent = is_string($currency) ? ($summary['spent_by_currency'][$currency] ?? 0.0) : 0.0;
            if ($limit > 0 && $spent >= $limit * 0.8) {
                $alerts[] = [
                    'task_id' => $task->id,
                    'spend' => round($spent, 8),
                    'limit' => $limit,
                    'currency' => $currency,
                ];
            }
        }

        return $alerts;
    }

    /**
     * @param  array<string, array<string, float>>  $dailySpend
     * @return list<array{currency: string, today: float, average_daily: float}>
     */
    private function costSpikes(array $dailySpend): array
    {
        $today = now()->toDateString();
        $currencies = [];
        foreach ($dailySpend as $day => $spendByCurrency) {
            foreach ($spendByCurrency as $currency => $amount) {
                $currencies[$currency] = true;
            }
        }

        $spikes = [];
        foreach (array_keys($currencies) as $currency) {
            $todaySpend = (float) ($dailySpend[$today][$currency] ?? 0.0);
            $baselineTotal = 0.0;
            for ($offset = 1; $offset <= 7; $offset++) {
                $day = now()->subDays($offset)->toDateString();
                $baselineTotal += (float) ($dailySpend[$day][$currency] ?? 0.0);
            }
            $average = $baselineTotal / 7;
            if ($average > 0 && $todaySpend > $average * 2 && $todaySpend >= 0.01) {
                $spikes[] = [
                    'currency' => $currency,
                    'today' => round($todaySpend, 8),
                    'average_daily' => round($average, 8),
                ];
            }
        }

        return $spikes;
    }
}
