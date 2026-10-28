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
    public function resolve(User $user, string $agentName, string $mode = TASK_MODE_SANDBOX): array
    {
        $modelSelection = $this->selectedModel($user, $agentName, $mode);
        $providerId = $modelSelection['provider'];
        $modelId = $modelSelection['model'];
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
    public function selectedModel(
        User $user,
        string $agentName,
        string $mode = TASK_MODE_LIVE,
    ): array {
        $settings = $user->llmSettings;

        if ($settings === null) {
            throw new InvalidArgumentException('Save provider settings and approve a model before running an agent.');
        }

        $agentChoice = $settings->agent_models[$agentName] ?? null;
        $providerId = $agentChoice['provider'] ?? $settings->provider;
        $modelId = $agentChoice['model'] ?? $settings->model;
        $approvedModels = $settings->approved_models ?? [];

        if ($mode === TASK_MODE_SANDBOX) {
            [$providerId, $modelId] = $this->leastExpensiveApprovedModel(
                $settings->model_pricing ?? [],
                $approvedModels,
                $providerId,
                $modelId,
            );
        }

        $this->assertModelIsApproved($approvedModels, $providerId, $modelId);

        $this->catalog->model($providerId, $modelId);

        return [
            'provider' => $providerId,
            'model' => $modelId,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $modelPricing
     * @param  array<int, array{provider?: string, model?: string}>  $approvedModels
     * @return array{string, string}
     */
    private function leastExpensiveApprovedModel(
        array $modelPricing,
        array $approvedModels,
        string $fallbackProvider,
        string $fallbackModel,
    ): array {
        $approvedKeys = [];
        foreach ($approvedModels as $approvedModel) {
            $approvedKeys[($approvedModel['provider'] ?? '').':'.($approvedModel['model'] ?? '')] = true;
        }

        $approvedPricing = array_values(array_filter(
            $modelPricing,
            static fn (array $pricing): bool => isset(
                $approvedKeys[($pricing['provider'] ?? '').':'.($pricing['model'] ?? '')],
            ),
        ));
        $currencies = array_unique(array_column($approvedPricing, 'currency'));

        if (count($currencies) !== 1) {
            return [$fallbackProvider, $fallbackModel];
        }

        usort(
            $approvedPricing,
            static fn (array $left, array $right): int => (
                ($left['input_cost_per_million'] + $left['output_cost_per_million'])
                <=> ($right['input_cost_per_million'] + $right['output_cost_per_million'])
            ),
        );
        $leastExpensive = $approvedPricing[0] ?? null;

        if ($leastExpensive === null) {
            return [$fallbackProvider, $fallbackModel];
        }

        return [$leastExpensive['provider'], $leastExpensive['model']];
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
