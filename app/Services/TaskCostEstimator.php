<?php

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Llm\UserLlmProviderResolver;
use App\Models\User;

class TaskCostEstimator
{
    public function __construct(
        private readonly AgentRegistry $agentRegistry,
        private readonly UserLlmProviderResolver $providerResolver,
    ) {}

    /**
     * @param  list<string>  $agentSequence
     * @return array<string, mixed>
     */
    public function estimate(User $user, string $prompt, array $agentSequence): array
    {
        $settings = $user->llmSettings;
        if ($settings === null) {
            return [
                'status' => 'unavailable',
                'currency' => null,
                'maximum_cost' => null,
                'maximum_tokens' => (int) config('llm.task_token_budget', 20000),
                'estimated_tokens' => null,
                'can_complete' => null,
                'agents' => [],
                'reason' => 'Save provider settings and approve a model before estimating cost.',
            ];
        }

        $modelPricing = [];
        foreach ($settings->model_pricing ?? [] as $pricing) {
            $modelPricing[$this->modelKey($pricing['provider'], $pricing['model'])] = $pricing;
        }

        $agentEstimates = [];
        $currencies = [];
        $totalMaximumCost = 0.0;
        $isCostAvailable = true;
        $canCompleteRoute = true;
        $maximumTokens = (int) config('llm.task_token_budget', 20000);
        $remainingTaskTokens = $maximumTokens;

        foreach ($agentSequence as $agentName) {
            $agentSpecification = $this->agentRegistry->activeSpec($agentName);
            $modelSelection = $this->providerResolver->selectedModel($user, $agentName);
            $modelKey = $this->modelKey($modelSelection['provider'], $modelSelection['model']);
            $pricing = $modelPricing[$modelKey] ?? null;
            $estimatedInputTokens = $this->estimatedInputTokens($prompt);
            $maximumAgentTokens = min(
                (int) ($agentSpecification['max_tokens'] ?? 2000),
                $remainingTaskTokens,
            );
            $canCompleteAgent = $maximumAgentTokens > $estimatedInputTokens;

            if (! $canCompleteAgent) {
                $canCompleteRoute = false;
                $isCostAvailable = false;
            }

            $estimatedOutputTokens = $canCompleteAgent
                ? $maximumAgentTokens - $estimatedInputTokens
                : 0;
            $remainingTaskTokens = max(0, $remainingTaskTokens - $maximumAgentTokens);
            $agentCost = null;

            if ($pricing === null) {
                $isCostAvailable = false;
            } else {
                $currency = $pricing['currency'];
                $currencies[$currency] = true;
                $agentCost = (
                    ($estimatedInputTokens * (float) $pricing['input_cost_per_million'])
                    + ($estimatedOutputTokens * (float) $pricing['output_cost_per_million'])
                ) / 1_000_000;
                $totalMaximumCost += $agentCost;
            }

            $agentEstimates[] = [
                'agent' => $agentName,
                'provider' => $modelSelection['provider'],
                'model' => $modelSelection['model'],
                'estimated_input_tokens' => $estimatedInputTokens,
                'maximum_output_tokens' => $estimatedOutputTokens,
                'maximum_total_tokens' => $maximumAgentTokens,
                'maximum_cost' => $agentCost === null ? null : round($agentCost, 8),
                'currency' => $pricing['currency'] ?? null,
            ];
        }

        if (count($currencies) > 1) {
            $isCostAvailable = false;
        }

        $estimatedTotalTokens = array_sum(array_column($agentEstimates, 'maximum_total_tokens'));
        $isWithinTaskBudget = $estimatedTotalTokens <= $maximumTokens;

        return [
            'status' => $isCostAvailable && $isWithinTaskBudget ? 'available' : 'unavailable',
            'currency' => count($currencies) === 1 ? array_key_first($currencies) : null,
            'maximum_cost' => $isCostAvailable && $isWithinTaskBudget ? round($totalMaximumCost, 8) : null,
            'maximum_tokens' => $maximumTokens,
            'estimated_tokens' => min($estimatedTotalTokens, $maximumTokens),
            'can_complete' => $canCompleteRoute && $isWithinTaskBudget,
            'agents' => $agentEstimates,
            'reason' => $isCostAvailable && $isWithinTaskBudget
                ? null
                : 'Pricing is missing for a selected model, currencies differ, or the route exceeds the task token budget.',
        ];
    }

    private function estimatedInputTokens(string $prompt): int
    {
        $charactersPerToken = max(1, (int) config('llm.estimated_characters_per_token', 3));
        $inputTokens = (int) ceil(mb_strlen($prompt) / $charactersPerToken);
        $contextAllowance = (int) config('llm.estimated_context_tokens_per_agent', 256);

        return max(1, $inputTokens + $contextAllowance);
    }

    private function modelKey(string $provider, string $model): string
    {
        return "{$provider}:{$model}";
    }
}
