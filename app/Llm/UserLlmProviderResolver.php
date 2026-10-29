<?php

namespace App\Llm;

use App\Models\User;
use App\Services\ModelRouter;
use InvalidArgumentException;

class UserLlmProviderResolver
{
    public function __construct(
        private readonly ModelRouter $modelRouter,
        private readonly ProviderFactory $providerFactory,
        private readonly ProviderCatalog $catalog,
    ) {}

    /**
     * @return array{provider: LlmProvider, model: string, routing: array<string, mixed>, fallbacks: list<array{provider: LlmProvider, model: string, selection: array{provider: string, model: string}}>}
     */
    public function resolve(
        User $user,
        string $agentName,
        string $mode = TASK_MODE_SANDBOX,
        ?string $prompt = null,
    ): array {
        $modelSelection = $this->routingDecision($user, $agentName, $mode, null, true, $prompt);
        $providerId = $modelSelection['provider'];
        $modelId = $modelSelection['model'];

        if (! $this->hasConfiguredCredentials($user, $providerId)) {
            throw new InvalidArgumentException('Save credentials for the selected provider before running an agent.');
        }

        $providerCredentials = $user->apiKeys()->where('provider', $providerId)->firstOrFail();
        $fallbackProviders = [];
        foreach ($modelSelection['fallback_candidates'] as $fallbackSelection) {
            if (! $this->hasConfiguredCredentials($user, $fallbackSelection['provider'])) {
                continue;
            }

            $fallbackCredentials = $user->apiKeys()
                ->where('provider', $fallbackSelection['provider'])
                ->firstOrFail();

            $fallbackProviders[] = [
                'provider' => $this->providerFactory->create(
                    $fallbackSelection['provider'],
                    $fallbackSelection['model'],
                    $fallbackCredentials->api_key,
                    $fallbackCredentials->base_url,
                    $user,
                ),
                'model' => $fallbackSelection['model'],
                'selection' => $fallbackSelection,
            ];
        }

        return [
            'provider' => $this->providerFactory->create(
                $providerId,
                $modelId,
                $providerCredentials->api_key,
                $providerCredentials->base_url,
                $user,
            ),
            'model' => $modelId,
            'routing' => $modelSelection,
            'fallbacks' => $fallbackProviders,
        ];
    }

    /**
     * Resolve only the approved model selection without constructing a provider.
     *
     * @return array{provider: string, model: string, brain_mode: string, policy: string, complexity: string, settings_fingerprint: string, reason: string, fallback_candidates: list<array{provider: string, model: string}>}
     */
    public function selectedModel(
        User $user,
        string $agentName,
        string $mode = TASK_MODE_LIVE,
        ?string $brainModeOverride = null,
        bool $requireConfiguredCredentials = false,
        ?string $prompt = null,
    ): array {
        return $this->routingDecision(
            $user,
            $agentName,
            $mode,
            $brainModeOverride,
            $requireConfiguredCredentials,
            $prompt,
        );
    }

    /**
     * @return array{provider: string, model: string, brain_mode: string, policy: string, complexity: string, settings_fingerprint: string, reason: string, fallback_candidates: list<array{provider: string, model: string}>}
     */
    public function routingDecision(
        User $user,
        string $agentName,
        string $mode,
        ?string $brainModeOverride = null,
        bool $requireConfiguredCredentials = false,
        ?string $prompt = null,
    ): array {
        $excludedModels = [];
        if ($agentName === 'Skeptic') {
            $writerSelection = $this->modelRouter->select(
                $user,
                'Writer',
                $mode,
                null,
                $requireConfiguredCredentials,
                $prompt,
            );
            $excludedModels[] = [
                'provider' => $writerSelection['provider'],
                'model' => $writerSelection['model'],
            ];
            foreach ($writerSelection['fallback_candidates'] as $fallbackSelection) {
                $excludedModels[] = $fallbackSelection;
            }

            if ($brainModeOverride === AGENT_BRAIN_MODE_FIXED) {
                $brainModeOverride = AGENT_BRAIN_MODE_AUTO;
            }
        }

        $selection = $this->modelRouter->select(
            $user,
            $agentName,
            $mode,
            $brainModeOverride,
            $requireConfiguredCredentials,
            $prompt,
            $excludedModels,
        );

        if ($agentName === 'Skeptic') {
            $selection['reason'] .= ' A distinct user-approved model is required for independent review; a shared Fixed model is not reused.';
        }

        return $selection;
    }

    public function hasConfiguredCredentials(User $user, string $providerId): bool
    {
        $provider = $this->catalog->provider($providerId);
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
}
