<?php

namespace App\Llm;

use App\Models\User;
use InvalidArgumentException;

class UserLlmProviderResolver
{
    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly ProviderFactory $providerFactory,
    ) {}

    /**
     * @return array{provider: LlmProvider, model: string}
     */
    public function resolve(User $user, string $agentName): array
    {
        $modelSelection = $this->selectedModel($user, $agentName);
        $providerId = $modelSelection['provider'];
        $modelId = $modelSelection['model'];
        $settings = $user->llmSettings;
        $providerCredentials = $user->apiKeys()->where('provider', $providerId)->first();

        if ($providerCredentials === null) {
            throw new InvalidArgumentException('Save credentials for the selected provider before running an agent.');
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
        ];
    }

    /**
     * Resolve only the approved model selection without constructing a provider.
     *
     * @return array{provider: string, model: string}
     */
    public function selectedModel(User $user, string $agentName): array
    {
        $settings = $user->llmSettings;

        if ($settings === null) {
            throw new InvalidArgumentException('Save provider settings and approve a model before running an agent.');
        }

        $agentChoice = $settings->agent_models[$agentName] ?? null;
        $providerId = $agentChoice['provider'] ?? $settings->provider;
        $modelId = $agentChoice['model'] ?? $settings->model;
        $approvedModels = $settings->approved_models ?? [];
        $this->assertModelIsApproved($approvedModels, $providerId, $modelId);

        $this->catalog->model($providerId, $modelId);

        return [
            'provider' => $providerId,
            'model' => $modelId,
        ];
    }

    /**
     * @param  array<int, array{provider?: string, model?: string}>  $approvedModels
     */
    private function assertModelIsApproved(array $approvedModels, string $providerId, string $modelId): void
    {
        foreach ($approvedModels as $approvedModel) {
            $isApproved = ($approvedModel['provider'] ?? null) === $providerId
                && ($approvedModel['model'] ?? null) === $modelId;

            if ($isApproved) {
                return;
            }
        }

        throw new InvalidArgumentException('The selected agent model is not approved in Settings.');
    }
}
