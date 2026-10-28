<?php

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Llm\ProviderCatalog;
use App\Models\User;
use InvalidArgumentException;

class ModelRouter
{
    private const COMPLEXITY_COMPLEX = 'complex';

    private const COMPLEXITY_MODERATE = 'moderate';

    private const COMPLEXITY_SIMPLE = 'simple';

    private const COMPLEXITY_COMPLEX_PROMPT_LENGTH = 1800;

    private const COMPLEXITY_SIMPLE_PROMPT_LENGTH = 240;

    private const CONTEXT_PERCENTILE_MODERATE = 0.5;

    private const CONTEXT_PERCENTILE_COMPLEX = 0.75;

    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly AgentRegistry $agentRegistry,
    ) {}

    /**
     * @return array{provider: string, model: string, brain_mode: string, policy: string, complexity: string, settings_fingerprint: string, reason: string, fallback_candidates: list<array{provider: string, model: string}>}
     */
    public function select(
        User $user,
        string $agentName,
        string $mode,
        ?string $brainModeOverride = null,
        bool $requireConfiguredCredentials = false,
        ?string $prompt = null,
    ): array {
        $settings = $user->llmSettings;
        if ($settings === null) {
            throw new InvalidArgumentException('Save provider settings and approve a model before running an agent.');
        }

        $brainMode = $brainModeOverride ?? $settings->agent_brains[$agentName] ?? AGENT_BRAIN_MODE_AUTO;
        $routingPolicy = $settings->routing_policy ?? MODEL_ROUTING_POLICY_CHEAPEST;
        $complexity = $this->estimateComplexity($prompt ?? '');
        $defaultSelection = [
            'provider' => $settings->provider,
            'model' => $settings->model,
        ];
        $settingsFingerprint = hash('sha256', json_encode([
            'provider' => $settings->provider,
            'model' => $settings->model,
            'approved_models' => $settings->approved_models ?? [],
            'agent_models' => $settings->agent_models ?? [],
            'agent_brains' => $settings->agent_brains ?? [],
            'routing_policy' => $routingPolicy,
            'fallback_provider' => $settings->fallback_provider,
            'fallback_model' => $settings->fallback_model,
            'model_pricing' => $settings->model_pricing ?? [],
        ], JSON_THROW_ON_ERROR));

        if ($brainMode === AGENT_BRAIN_MODE_FIXED) {
            $selectedModel = $defaultSelection;
            $reason = 'The agent is configured to use the fixed Settings model.';
        } elseif ($brainMode === AGENT_BRAIN_MODE_CUSTOM) {
            $selectedModel = $settings->agent_models[$agentName] ?? null;
            if ($selectedModel === null) {
                throw new InvalidArgumentException("Agent [{$agentName}] is in Custom mode without a selected model.");
            }
            $reason = 'The agent is configured to use its custom approved model.';
        } elseif ($brainMode === AGENT_BRAIN_MODE_AUTO) {
            [$selectedModel, $reason] = $this->selectAutomatically(
                $user,
                $agentName,
                $routingPolicy,
                $settings->approved_models ?? [],
                $settings->model_pricing ?? [],
                $defaultSelection,
                $requireConfiguredCredentials,
                $complexity,
                $prompt ?? '',
            );
        } else {
            throw new InvalidArgumentException("Unsupported Brain mode [{$brainMode}] for agent [{$agentName}].");
        }

        $this->assertApprovedModel($settings->approved_models ?? [], $selectedModel);
        $this->assertCapabilities(
            $user,
            $agentName,
            $settings->approved_models ?? [],
            $selectedModel,
            $prompt,
        );
        $modelCapabilities = $this->catalog->model($selectedModel['provider'], $selectedModel['model']);

        return [
            ...$selectedModel,
            'brain_mode' => $brainMode,
            'policy' => $routingPolicy,
            'complexity' => $complexity,
            'settings_fingerprint' => $settingsFingerprint,
            'reason' => $reason,
            'fallback_candidates' => $this->fallbackCandidates(
                $user,
                $agentName,
                $settings->approved_models ?? [],
                $selectedModel,
                $modelCapabilities,
                [
                    'provider' => $settings->fallback_provider,
                    'model' => $settings->fallback_model,
                ],
                $requireConfiguredCredentials,
                $prompt,
            ),
        ];
    }

    /**
     * @param  list<array{provider?: string, model?: string}>  $approvedModels
     * @param  list<array<string, mixed>>  $modelPricing
     * @param  array{provider: string, model: string}  $defaultSelection
     * @return array{array{provider: string, model: string}, string}
     */
    private function selectAutomatically(
        User $user,
        string $agentName,
        string $routingPolicy,
        array $approvedModels,
        array $modelPricing,
        array $defaultSelection,
        bool $requireConfiguredCredentials,
        string $complexity,
        string $prompt,
    ): array {
        $compatibleModels = $this->compatibleApprovedModels(
            $user,
            $agentName,
            $approvedModels,
            $requireConfiguredCredentials,
        );
        if ($compatibleModels === []) {
            throw new InvalidArgumentException(
                $requireConfiguredCredentials
                    ? "No approved model with configured credentials meets the capabilities required by agent [{$agentName}]."
                    : "No approved model meets the capabilities required by agent [{$agentName}].",
            );
        }

        $agentSpecification = $this->agentRegistry->activeSpec($agentName);
        $maximumAgentTokens = (int) ($agentSpecification['max_tokens'] ?? 2000);
        $estimatedInputTokens = $this->estimatedPromptTokens($prompt);
        $estimatedOutputTokens = max(0, $maximumAgentTokens - $estimatedInputTokens);
        $pricedModels = $this->pricedModels(
            $compatibleModels,
            $modelPricing,
            $estimatedInputTokens,
            $estimatedOutputTokens,
        );
        $currencies = array_values(array_unique(array_column($pricedModels, 'currency')));

        if ($routingPolicy === MODEL_ROUTING_POLICY_BEST_QUALITY) {
            return [
                $this->compatibleDefaultOrFirst($defaultSelection, $compatibleModels),
                'Verified model-quality evaluation data is not available; using the configured default model when compatible.',
            ];
        }

        if ($pricedModels === [] || count($currencies) !== 1) {
            return [
                $this->compatibleDefaultOrFirst($defaultSelection, $compatibleModels),
                'Comparable user-entered pricing is unavailable; using the configured default compatible approved model.',
            ];
        }

        if ($routingPolicy === MODEL_ROUTING_POLICY_BALANCED) {
            $minimumContext = $this->balancedContextThreshold($pricedModels, $complexity);
            $balancedModels = array_values(array_filter(
                $pricedModels,
                static fn (array $candidate): bool => $candidate['context_window'] >= $minimumContext,
            ));
            usort($balancedModels, static function (array $left, array $right): int {
                $priceComparison = $left['total_price'] <=> $right['total_price'];

                return $priceComparison !== 0
                    ? $priceComparison
                    : $right['context_window'] <=> $left['context_window'];
            });
            $chosenModel = $balancedModels[0] ?? $pricedModels[0];

            return [
                $this->modelSelection($chosenModel),
                "Balanced policy estimated task complexity as {$complexity} and chose the least expensive compatible priced model with adequate context capacity.",
            ];
        }

        if ($routingPolicy !== MODEL_ROUTING_POLICY_CHEAPEST) {
            throw new InvalidArgumentException("Unsupported model routing policy [{$routingPolicy}].");
        }

        usort(
            $pricedModels,
            static fn (array $left, array $right): int => $left['total_price'] <=> $right['total_price'],
        );

        return [
            $this->modelSelection($pricedModels[0]),
            'Cheapest policy selected the compatible approved model with the lowest estimated cost at the agent token limit using user-entered input and output rates.',
        ];
    }

    /**
     * @param  list<array{provider?: string, model?: string}>  $approvedModels
     * @return list<array{provider: string, model: string, context_window: int, tools: bool}>
     */
    private function compatibleApprovedModels(
        User $user,
        string $agentName,
        array $approvedModels,
        bool $requireConfiguredCredentials = false,
        ?string $prompt = null,
    ): array {
        $agentSpecification = $this->agentRegistry->activeSpec($agentName);
        $requiresTools = ($agentSpecification['allowed_tools'] ?? []) !== [];
        $minimumContext = (int) ($agentSpecification['max_tokens'] ?? 0)
            + $this->estimatedPromptTokens($prompt ?? '');
        $compatibleModels = [];

        foreach ($approvedModels as $approvedModel) {
            $providerId = $approvedModel['provider'] ?? '';
            $modelId = $approvedModel['model'] ?? '';
            $provider = $this->catalog->provider($providerId);
            $capabilities = $this->catalog->model($providerId, $modelId);

            if ($requireConfiguredCredentials && ! $this->hasConfiguredCredentials($user, $providerId, $provider)) {
                continue;
            }

            if ($requiresTools && ! ($capabilities['tools'] ?? false)) {
                continue;
            }

            $contextWindow = (int) ($capabilities['context_window'] ?? 0);
            if ($contextWindow > 0 && $contextWindow < $minimumContext) {
                continue;
            }

            $compatibleModels[] = [
                'provider' => $providerId,
                'model' => $modelId,
                'context_window' => $contextWindow,
                'tools' => (bool) ($capabilities['tools'] ?? false),
            ];
        }

        return $compatibleModels;
    }

    /**
     * @param  list<array{provider: string, model: string, context_window: int, tools: bool}>  $compatibleModels
     * @param  list<array<string, mixed>>  $modelPricing
     * @return list<array{provider: string, model: string, context_window: int, tools: bool, currency: string, total_price: float}>
     */
    private function pricedModels(
        array $compatibleModels,
        array $modelPricing,
        int $estimatedInputTokens,
        int $estimatedOutputTokens,
    ): array {
        $pricingByKey = [];
        foreach ($modelPricing as $pricing) {
            $pricingByKey[$this->modelKey($pricing['provider'] ?? '', $pricing['model'] ?? '')] = $pricing;
        }

        $pricedModels = [];
        foreach ($compatibleModels as $compatibleModel) {
            $pricing = $pricingByKey[$this->modelKey($compatibleModel['provider'], $compatibleModel['model'])] ?? null;
            if ($pricing === null) {
                continue;
            }

            $pricedModels[] = [
                ...$compatibleModel,
                'currency' => $pricing['currency'],
                'total_price' => (
                    ($estimatedInputTokens * (float) $pricing['input_cost_per_million'])
                    + ($estimatedOutputTokens * (float) $pricing['output_cost_per_million'])
                ) / 1_000_000,
            ];
        }

        return $pricedModels;
    }

    /**
     * @param  list<array{provider: string, model: string, context_window: int, tools: bool, currency: string, total_price: float}>  $pricedModels
     */
    private function balancedContextThreshold(array $pricedModels, string $complexity): int
    {
        $contextWindows = array_column($pricedModels, 'context_window');
        sort($contextWindows);

        if ($complexity === self::COMPLEXITY_SIMPLE) {
            return $contextWindows[0];
        }

        $percentile = $complexity === self::COMPLEXITY_COMPLEX
            ? self::CONTEXT_PERCENTILE_COMPLEX
            : self::CONTEXT_PERCENTILE_MODERATE;

        return $contextWindows[(int) floor((count($contextWindows) - 1) * $percentile)];
    }

    private function estimateComplexity(string $prompt): string
    {
        $normalizedPrompt = mb_strtolower($prompt);
        $hasComplexityCue = preg_match(
            '/\b(analy[sz]e|compare|evaluate|step[- ]by[- ]step|comprehensive|multiple|trade[- ]offs)\b|বিশ্লেষণ|তুলনা|ধাপে ধাপে|বিস্তারিত/u',
            $normalizedPrompt,
        ) === 1;

        if (mb_strlen($normalizedPrompt) >= 1800 || $hasComplexityCue) {
            return self::COMPLEXITY_COMPLEX;
        }

        if (mb_strlen($normalizedPrompt) < 240) {
            return self::COMPLEXITY_SIMPLE;
        }

        return self::COMPLEXITY_MODERATE;
    }

    private function estimatedPromptTokens(string $prompt): int
    {
        $charactersPerToken = max(1, (int) config('llm.estimated_characters_per_token', 3));
        $contextAllowance = (int) config('llm.estimated_context_tokens_per_agent', 256);

        return (int) ceil(mb_strlen($prompt) / $charactersPerToken) + $contextAllowance;
    }

    /**
     * @param  array{provider: string, model: string}  $defaultSelection
     * @param  list<array{provider: string, model: string}>  $compatibleModels
     * @return array{provider: string, model: string}
     */
    private function compatibleDefaultOrFirst(array $defaultSelection, array $compatibleModels): array
    {
        foreach ($compatibleModels as $compatibleModel) {
            if ($compatibleModel['provider'] === $defaultSelection['provider']
                && $compatibleModel['model'] === $defaultSelection['model']) {
                return [
                    'provider' => $compatibleModel['provider'],
                    'model' => $compatibleModel['model'],
                ];
            }
        }

        return [
            'provider' => $compatibleModels[0]['provider'],
            'model' => $compatibleModels[0]['model'],
        ];
    }

    /**
     * @param  array{provider: string, model: string, context_window: int, tools: bool, currency: string, total_price: float}  $model
     * @return array{provider: string, model: string}
     */
    private function modelSelection(array $model): array
    {
        return [
            'provider' => $model['provider'],
            'model' => $model['model'],
        ];
    }

    /**
     * @param  list<array{provider?: string, model?: string}>  $approvedModels
     * @param  array{provider: string, model: string}  $selection
     */
    private function assertApprovedModel(array $approvedModels, array $selection): void
    {
        foreach ($approvedModels as $approvedModel) {
            if (($approvedModel['provider'] ?? null) === $selection['provider']
                && ($approvedModel['model'] ?? null) === $selection['model']) {
                return;
            }
        }

        throw new InvalidArgumentException('The selected agent model is not approved in Settings.');
    }

    /**
     * @param  list<array{provider?: string, model?: string}>  $approvedModels
     * @param  array{provider: string, model: string}  $selection
     */
    private function assertCapabilities(
        User $user,
        string $agentName,
        array $approvedModels,
        array $selection,
        ?string $prompt = null,
    ): void {
        foreach ($this->compatibleApprovedModels($user, $agentName, $approvedModels, false, $prompt) as $compatibleModel) {
            if ($compatibleModel['provider'] === $selection['provider']
                && $compatibleModel['model'] === $selection['model']) {
                return;
            }
        }

        throw new InvalidArgumentException(
            "The selected model does not meet the capabilities required by agent [{$agentName}].",
        );
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function hasConfiguredCredentials(User $user, string $providerId, array $provider): bool
    {
        $credentials = $user->apiKeys()->where('provider', $providerId)->first();
        if ($credentials === null) {
            return false;
        }

        if ($provider['requires_api_key']) {
            return is_string($credentials->api_key) && trim($credentials->api_key) !== '';
        }

        return ! $provider['custom_base_url']
            || (is_string($credentials->base_url) && trim($credentials->base_url) !== '');
    }

    /**
     * @param  list<array{provider?: string, model?: string}>  $approvedModels
     * @param  list<array<string, mixed>>  $modelPricing
     * @param  array{provider: string, model: string}  $selectedModel
     * @param  array<string, mixed>  $selectedCapabilities
     * @param  array{provider: ?string, model: ?string}  $configuredFallback
     * @return list<array{provider: string, model: string}>
     */
    private function fallbackCandidates(
        User $user,
        string $agentName,
        array $approvedModels,
        array $selectedModel,
        array $selectedCapabilities,
        array $configuredFallback,
        bool $requireConfiguredCredentials,
        ?string $prompt,
    ): array {
        $compatibleModels = $this->compatibleApprovedModels(
            $user,
            $agentName,
            $approvedModels,
            $requireConfiguredCredentials,
            $prompt,
        );
        $compatibleModels = array_values(array_filter(
            $compatibleModels,
            static fn (array $model): bool => ! (
                $model['provider'] === $selectedModel['provider']
                && $model['model'] === $selectedModel['model']
            ),
        ));
        $fallbackCandidates = [];

        if ($configuredFallback['provider'] === null || $configuredFallback['model'] === null) {
            return [];
        }

        foreach ($compatibleModels as $compatibleModel) {
            if ($compatibleModel['provider'] === $configuredFallback['provider']
                && $compatibleModel['model'] === $configuredFallback['model']
                && ($compatibleModel['tools'] ?? false) === (bool) ($selectedCapabilities['tools'] ?? false)) {
                $fallbackCandidates[] = [
                    'provider' => $compatibleModel['provider'],
                    'model' => $compatibleModel['model'],
                ];
                break;
            }
        }

        return $fallbackCandidates;
    }

    private function modelKey(string $provider, string $model): string
    {
        return "{$provider}:{$model}";
    }
}
