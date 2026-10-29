<?php

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Llm\UserLlmProviderResolver;
use App\Models\User;
use InvalidArgumentException;

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
    public function estimate(
        User $user,
        string $prompt,
        array $agentSequence,
        string $mode = TASK_MODE_SANDBOX,
        ?string $brainModeOverride = null,
    ): array {
        $settings = $user->llmSettings;
        if ($settings === null) {
            return [
                'status' => 'unavailable',
                'currency' => null,
                'maximum_cost' => null,
                'maximum_tokens' => $this->maximumTaskTokens($mode),
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
        $maximumTokens = $this->maximumTaskTokens($mode);
        $remainingTaskTokens = $maximumTokens;

        foreach ($agentSequence as $agentIndex => $agentName) {
            $agentSpecification = $this->agentRegistry->activeSpec($agentName);
            try {
                $modelSelection = $this->providerResolver->selectedModel(
                    $user,
                    $agentName,
                    $mode,
                    $brainModeOverride,
                    false,
                    $prompt,
                );
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 'unavailable',
                    'currency' => null,
                    'maximum_cost' => null,
                    'maximum_tokens' => $maximumTokens,
                    'estimated_tokens' => null,
                    'can_complete' => false,
                    'agents' => [],
                    'reason' => $exception->getMessage(),
                ];
            }
            $modelKey = $this->modelKey($modelSelection['provider'], $modelSelection['model']);
            $pricing = $modelPricing[$modelKey] ?? null;
            $estimatedInputTokens = $this->estimatedInputTokens($prompt);
            $agentsRemaining = count($agentSequence) - $agentIndex;
            $agentTokenShare = (int) ceil($remainingTaskTokens / max(1, $agentsRemaining));
            $maximumAgentTokens = min((int) ($agentSpecification['max_tokens'] ?? 2000), $agentTokenShare);
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
            $fallbackCost = 0.0;
            $fallbackPricingAvailable = true;
            $fallbackCurrencies = [];

            if ($pricing === null) {
                $isCostAvailable = false;
            } else {
                $currency = $pricing['currency'];
                $currencies[$currency] = true;
                $agentCost = (
                    ($estimatedInputTokens * (float) $pricing['input_cost_per_million'])
                    + ($estimatedOutputTokens * (float) $pricing['output_cost_per_million'])
                ) / 1_000_000;
            }

            foreach ($modelSelection['fallback_candidates'] as $fallbackSelection) {
                $fallbackPricing = $modelPricing[$this->modelKey(
                    $fallbackSelection['provider'],
                    $fallbackSelection['model'],
                )] ?? null;

                if ($fallbackPricing === null) {
                    $fallbackPricingAvailable = false;
                    $isCostAvailable = false;

                    continue;
                }

                $fallbackCurrency = $fallbackPricing['currency'];
                $currencies[$fallbackCurrency] = true;
                $fallbackCurrencies[$fallbackCurrency] = true;
                $fallbackCost += (
                    ($estimatedInputTokens * (float) $fallbackPricing['input_cost_per_million'])
                    + ($estimatedOutputTokens * (float) $fallbackPricing['output_cost_per_million'])
                ) / 1_000_000;
            }

            $fallbackCurrency = count($fallbackCurrencies) === 1
                ? array_key_first($fallbackCurrencies)
                : null;
            $hasFallback = $modelSelection['fallback_candidates'] !== [];
            $agentMaximumCost = $agentCost !== null
                && $fallbackPricingAvailable
                && (! $hasFallback || $pricing['currency'] === $fallbackCurrency)
                ? $agentCost + $fallbackCost
                : null;
            if ($agentMaximumCost !== null) {
                $totalMaximumCost += $agentMaximumCost;
            }

            $agentEstimates[] = [
                'agent' => $agentName,
                'provider' => $modelSelection['provider'],
                'model' => $modelSelection['model'],
                'brain_mode' => $modelSelection['brain_mode'],
                'routing_policy' => $modelSelection['policy'],
                'complexity' => $modelSelection['complexity'],
                'routing_reason' => $modelSelection['reason'],
                'fallback_candidates' => $modelSelection['fallback_candidates'],
                'estimated_input_tokens' => $estimatedInputTokens,
                'maximum_output_tokens' => $estimatedOutputTokens,
                'maximum_total_tokens' => $maximumAgentTokens,
                'primary_estimated_cost' => $agentCost === null ? null : round($agentCost, 8),
                'fallback_maximum_cost' => $fallbackPricingAvailable ? round($fallbackCost, 8) : null,
                'fallback_currency' => $fallbackCurrency,
                'maximum_cost' => $agentMaximumCost === null ? null : round($agentMaximumCost, 8),
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
                : 'Pricing is missing for a selected model or fallback, currencies differ, or the route exceeds the task token budget.',
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

    private function maximumTaskTokens(string $mode): int
    {
        $configurationKey = $mode === TASK_MODE_SANDBOX
            ? 'llm.sandbox_task_token_budget'
            : 'llm.task_token_budget';

        return (int) config($configurationKey, config('llm.task_token_budget', 20000));
    }
}
