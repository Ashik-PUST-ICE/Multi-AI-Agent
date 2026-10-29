<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Task;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

class TaskCostBudgetService
{
    /**
     * @param  array<string, mixed>  $estimate
     * @return array{maximum_cost: float, currency: string, account_maximum_cost: float|null, task_maximum_cost: float|null}|null
     */
    public function validateSubmission(User $user, array $estimate, ?float $taskMaximumCost): ?array
    {
        $settings = $user->llmSettings;
        $accountMaximumCost = $settings?->max_task_cost;
        $accountCurrency = $settings?->budget_currency;

        if ($taskMaximumCost !== null && $accountMaximumCost !== null && $taskMaximumCost > $accountMaximumCost) {
            throw new InvalidArgumentException('The task cost limit cannot exceed the account task cost limit.');
        }

        $maximumCost = $taskMaximumCost ?? $accountMaximumCost;
        if ($maximumCost === null) {
            return null;
        }

        $currency = $accountCurrency ?? $estimate['currency'] ?? null;
        if (! is_string($currency) || $currency === '') {
            throw new InvalidArgumentException('Set a budget currency in Settings before configuring a cost limit.');
        }

        if (($estimate['status'] ?? null) !== 'available'
            || ! is_numeric($estimate['maximum_cost'] ?? null)
            || ($estimate['currency'] ?? null) !== $currency) {
            throw new InvalidArgumentException(
                'A cost limit requires complete user-entered pricing in the same currency for every selected and fallback model.',
            );
        }

        if ((float) $estimate['maximum_cost'] > (float) $maximumCost) {
            throw new InvalidArgumentException(
                'The estimated maximum task cost exceeds the configured cost limit. Reduce the task scope or adjust the limit in Settings.',
            );
        }

        return [
            'maximum_cost' => (float) $maximumCost,
            'currency' => $currency,
            'account_maximum_cost' => $accountMaximumCost === null ? null : (float) $accountMaximumCost,
            'task_maximum_cost' => $taskMaximumCost,
        ];
    }

    /**
     * Rejects a model request whose estimated maximum would exceed the task's configured spending ceiling.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array{currency: string, input_cost_per_million: float, output_cost_per_million: float}|null  $pricing
     */
    public function assertCallWithinBudget(
        AgentRun $agentRun,
        string $provider,
        string $model,
        array $messages,
        int $maximumOutputTokens,
        ?array $pricing,
    ): void {
        $task = $agentRun->task()->with('user.llmSettings')->first();
        if ($task === null) {
            throw new RuntimeException('The task for this model request could not be found.');
        }

        $budget = ($task->input ?? [])['cost_budget'] ?? null;
        if (! is_array($budget)) {
            return;
        }

        $currency = $budget['currency'] ?? null;
        if ($pricing === null || ($pricing['currency'] ?? null) !== $currency) {
            throw new RuntimeException('The selected model has no matching user-entered pricing for the configured cost limit.');
        }

        $estimatedInputTokens = $this->estimateInputTokens($messages);
        $maximumCallCost = (
            ($estimatedInputTokens * $pricing['input_cost_per_million'])
            + ($maximumOutputTokens * $pricing['output_cost_per_million'])
        ) / 1_000_000;
        $spendSummary = $this->summary($task);
        $otherCurrencies = array_diff(array_keys($spendSummary['spent_by_currency']), [$currency]);
        if ($otherCurrencies !== []) {
            throw new RuntimeException('Task spending contains a currency that differs from its configured cost limit.');
        }
        $spent = $spendSummary['spent_by_currency'][$currency] ?? 0.0;
        $limit = (float) ($budget['maximum_cost'] ?? 0);

        if ((float) $spent + $maximumCallCost > $limit) {
            throw new RuntimeException('The task cost limit would be exceeded by the maximum cost of the next model request.');
        }
    }

    /**
     * @param  array<string, int|float>  $usage
     * @param  array{currency: string, input_cost_per_million: float, output_cost_per_million: float}|null  $pricing
     * @return array{amount: float, currency: string}|null
     */
    public function recordCall(
        AgentRun $agentRun,
        string $provider,
        string $model,
        array $usage,
        ?array $pricing,
    ): ?array {
        $cost = $pricing === null
            ? null
            : [
                'amount' => round((
                    ((int) ($usage['input_tokens'] ?? 0) * $pricing['input_cost_per_million'])
                    + ((int) ($usage['output_tokens'] ?? 0) * $pricing['output_cost_per_million'])
                ) / 1_000_000, 8),
                'currency' => $pricing['currency'],
            ];

        app(AgentActivityLogger::class)->recordEvent($agentRun, 'agent.llm_call', [
            'provider' => $provider,
            'model' => $model,
            'usage' => $usage,
            'cost' => $cost,
        ]);

        return $cost;
    }

    /**
     * @return array{spent_by_currency: array<string, float>, total: float|null, currency: string|null, priced_call_count: int, unpriced_call_count: int}
     */
    public function summary(Task $task): array
    {
        $events = $task->runEvents()
            ->where('event_type', 'agent.llm_call')
            ->get(['payload']);
        $spentByCurrency = [];
        $pricedCallCount = 0;
        $unpricedCallCount = 0;

        foreach ($events as $event) {
            $cost = $event->payload['cost'] ?? null;
            if (! is_array($cost)
                || ! is_numeric($cost['amount'] ?? null)
                || ! is_string($cost['currency'] ?? null)) {
                $unpricedCallCount++;

                continue;
            }

            $currency = $cost['currency'];
            $spentByCurrency[$currency] = ($spentByCurrency[$currency] ?? 0.0) + (float) $cost['amount'];
            $pricedCallCount++;
        }

        $currency = count($spentByCurrency) === 1 ? array_key_first($spentByCurrency) : null;

        return [
            'spent_by_currency' => array_map(
                static fn (float $amount): float => round($amount, 8),
                $spentByCurrency,
            ),
            'total' => $currency === null ? null : round($spentByCurrency[$currency], 8),
            'currency' => $currency,
            'priced_call_count' => $pricedCallCount,
            'unpriced_call_count' => $unpricedCallCount,
        ];
    }

    /**
     * @return array{amount: float, currency: string}|null
     */
    public function agentRunCost(AgentRun $agentRun): ?array
    {
        $events = $agentRun->runEvents()
            ->where('event_type', 'agent.llm_call')
            ->get(['payload']);
        $amounts = [];

        foreach ($events as $event) {
            $cost = $event->payload['cost'] ?? null;
            if (! is_array($cost)
                || ! is_numeric($cost['amount'] ?? null)
                || ! is_string($cost['currency'] ?? null)) {
                continue;
            }

            $currency = $cost['currency'];
            $amounts[$currency] = ($amounts[$currency] ?? 0.0) + (float) $cost['amount'];
        }

        if (count($amounts) !== 1) {
            return null;
        }

        $currency = array_key_first($amounts);

        return [
            'amount' => round($amounts[$currency], 8),
            'currency' => $currency,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function estimateInputTokens(array $messages): int
    {
        $encodedMessages = json_encode($messages, JSON_THROW_ON_ERROR);
        $charactersPerToken = max(1, (int) config('llm.estimated_characters_per_token', 3));

        return max(1, (int) ceil(mb_strlen($encodedMessages) / $charactersPerToken));
    }
}
