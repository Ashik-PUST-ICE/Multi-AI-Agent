<?php

namespace App\Services;

use App\Models\Task;

class TaskCheckInService
{
    /**
     * @return array<string, mixed>
     */
    public function evaluate(Task $task): array
    {
        $input = $task->input ?? [];
        $planReview = $input['plan_review'] ?? [];

        if (is_array($planReview)
            && (($planReview['required'] ?? false) === true)
            && (($planReview['status'] ?? 'pending') === 'pending')) {
            return [
                'status' => 'needs_plan_review',
                'severity' => 'warning',
                'reason' => 'The task plan is awaiting approval before execution can begin.',
            ];
        }

        $costBudget = $input['cost_budget'] ?? null;
        if (is_array($costBudget) && is_numeric($costBudget['maximum_cost'] ?? null)) {
            $maximumCost = (float) $costBudget['maximum_cost'];
            $currency = $costBudget['currency'] ?? null;
            $spendSummary = app(TaskCostBudgetService::class)->summary($task);
            $spent = is_string($currency)
                ? ($spendSummary['spent_by_currency'][$currency] ?? 0.0)
                : 0.0;
            if ($maximumCost > 0 && $spent >= $maximumCost * 0.8) {
                return [
                    'status' => 'budget_watch',
                    'severity' => 'warning',
                    'reason' => 'Recorded model usage has reached at least 80% of the configured task cost limit.',
                    'spent' => round((float) $spent, 8),
                    'maximum_cost' => $maximumCost,
                    'currency' => $currency,
                ];
            }
        }

        $runStates = $task->agentRuns()->select('status')->get()->pluck('status')->all();
        if ($runStates !== [] && in_array(AGENT_RUN_STATUS_RUNNING, $runStates, true)) {
            return [
                'status' => 'in_progress',
                'severity' => 'info',
                'reason' => 'The task is still progressing and is within its current run window.',
            ];
        }

        return [
            'status' => 'stable',
            'severity' => 'info',
            'reason' => 'No immediate steering or review action is required.',
        ];
    }
}
