<?php

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Llm\CustomBaseUrlPolicy;
use App\Llm\ProviderCatalog;
use App\Llm\ProviderFactory;
use App\Models\AgentSpec;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

class ProviderSettingsService
{
    use ResponseTrait;

    private const SUPPORTED_AGENTS = ['Orchestrator', 'Research', 'Writer', 'Reviewer'];

    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly ProviderFactory $providerFactory,
        private readonly CustomBaseUrlPolicy $baseUrlPolicy,
        private readonly AuditLogService $auditLogService,
        private readonly AgentRegistry $agentRegistry,
    ) {}

    public function getCatalog(): JsonResponse
    {
        return $this->success([
            'providers' => $this->catalog->publicCatalog(),
        ]);
    }

    public function getSettings(User $user): JsonResponse
    {
        $settings = $user->llmSettings;
        $apiKeys = $user->apiKeys()->get();
        $keyStatuses = [];
        $legacyKeyStatuses = [];

        foreach ($apiKeys as $apiKey) {
            $status = [
                'configured' => $apiKey->api_key !== null || $apiKey->base_url !== null,
                'masked' => $apiKey->maskedKey(),
                'custom_base_url' => $apiKey->base_url,
            ];
            if ($apiKey->agent_name === null) {
                $legacyKeyStatuses[$apiKey->provider] = $status;
            } else {
                $keyStatuses[$apiKey->agent_name][$apiKey->provider] = $status;
            }
        }

        $settingsData = [
            'settings' => $settings?->only([
                'provider',
                'model',
                'approved_models',
                'agent_models',
                'fallback_provider',
                'fallback_model',
                'model_pricing',
                'max_task_cost',
                'budget_currency',
                'agent_brains',
                'routing_policy',
            ]),
            'keys' => $keyStatuses,
            'legacy_keys' => $legacyKeyStatuses,
            'agents' => AgentSpec::query()
                ->where('status', AGENT_SPEC_STATUS_ACTIVE)
                ->distinct()
                ->orderBy('agent_name')
                ->pluck('agent_name'),
            'catalog' => $this->catalog->publicCatalog(),
        ];

        return $this->success($settingsData);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveSettings(User $user, array $data): JsonResponse
    {
        try {
            $approvedModels = $this->normalizeApprovedModels($data['approved_models']);
            $existingSettings = $user->llmSettings()->first();
            $previousApprovedModels = $existingSettings?->approved_models ?? [];
            $previousSelection = [
                'provider' => $existingSettings?->provider,
                'model' => $existingSettings?->model,
            ];
            $providerId = $data['provider'];
            $modelId = $data['model'];
            $this->assertApprovedModel($approvedModels, $providerId, $modelId);

            $fallbackProvider = $data['fallback_provider'] ?? null;
            $fallbackModel = $data['fallback_model'] ?? null;

            if ($fallbackProvider !== null && $fallbackModel !== null) {
                $this->assertApprovedModel($approvedModels, $fallbackProvider, $fallbackModel);
            }

            $agentModels = $this->normalizeAgentModels(
                $data['agent_models'] ?? $existingSettings?->agent_models ?? [],
                $approvedModels,
            );
            $agentBrains = $this->normalizeAgentBrains(
                $data['agent_brains'] ?? $existingSettings?->agent_brains ?? [],
            );

            foreach ($agentBrains as $agentName => $brainMode) {
                if ($brainMode === AGENT_BRAIN_MODE_CUSTOM && ! isset($agentModels[$agentName])) {
                    throw new InvalidArgumentException("Agent [{$agentName}] must have a selected model in Custom mode.");
                }
            }
            $existingPricing = $existingSettings?->model_pricing ?? [];
            $pricingData = array_key_exists('model_pricing', $data)
                ? $data['model_pricing']
                : $this->retainApprovedModelPricing($existingPricing, $approvedModels);
            $modelPricing = $this->normalizeModelPricing(
                $pricingData,
                $approvedModels,
            );
            $maxTaskCost = array_key_exists('max_task_cost', $data)
                ? $data['max_task_cost']
                : $existingSettings?->max_task_cost;
            $budgetCurrency = array_key_exists('budget_currency', $data)
                ? ($data['budget_currency'] === null ? null : strtoupper($data['budget_currency']))
                : $existingSettings?->budget_currency;

            if ($maxTaskCost !== null && $budgetCurrency === null) {
                throw new InvalidArgumentException('Choose a budget currency when setting an account task cost limit.');
            }

            $settings = $user->llmSettings()->updateOrCreate([], [
                'provider' => $providerId,
                'model' => $modelId,
                'approved_models' => $approvedModels,
                'agent_models' => $agentModels,
                'agent_brains' => $agentBrains,
                'routing_policy' => $data['routing_policy']
                    ?? $existingSettings?->routing_policy
                    ?? MODEL_ROUTING_POLICY_CHEAPEST,
                'fallback_provider' => $fallbackProvider,
                'fallback_model' => $fallbackModel,
                'model_pricing' => $modelPricing,
                'max_task_cost' => $maxTaskCost,
                'budget_currency' => $budgetCurrency,
            ]);
            if ($previousApprovedModels !== $approvedModels) {
                $this->auditLogService->record($user, 'llm.models.approved', $settings, [
                    'previous_models' => $previousApprovedModels,
                    'approved_models' => $approvedModels,
                ], $user);
            }
            if ($previousSelection !== ['provider' => $providerId, 'model' => $modelId]) {
                $this->auditLogService->record($user, 'llm.model.selected', $settings, [
                    'previous_selection' => $previousSelection,
                    'selection' => ['provider' => $providerId, 'model' => $modelId],
                ], $user);
            }
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }

        $settingsData = $settings->only([
            'provider',
            'model',
            'approved_models',
            'agent_models',
            'fallback_provider',
            'fallback_model',
            'model_pricing',
            'max_task_cost',
            'budget_currency',
            'agent_brains',
            'routing_policy',
        ]);

        return $this->success(
            ['settings' => $settingsData],
            UPDATED_SUCCESSFULLY,
        );
    }

    public function saveApiKey(
        User $user,
        string $agentName,
        string $providerId,
        ?string $apiKey,
        ?string $baseUrl,
    ): JsonResponse {
        try {
            $this->agentRegistry->activeSpec($agentName);
            $provider = $this->catalog->provider($providerId);
            $normalizedApiKey = $apiKey === null ? null : trim($apiKey);

            if ($normalizedApiKey === '') {
                $normalizedApiKey = null;
            }

            if ($provider['requires_api_key'] && $normalizedApiKey === null) {
                throw new InvalidArgumentException('An API key is required for this provider.');
            }

            if ($provider['custom_base_url']) {
                if ($baseUrl === null || trim($baseUrl) === '') {
                    throw new InvalidArgumentException('A custom base URL is required for this provider.');
                }

                $allowPrivateEndpoints = $this->baseUrlPolicy->userMayUsePrivateEndpoints($user);
                $normalizedBaseUrl = $this->baseUrlPolicy->validate($baseUrl, $allowPrivateEndpoints);
            } else {
                if ($baseUrl !== null && trim($baseUrl) !== '') {
                    throw new InvalidArgumentException('This provider does not allow a custom base URL.');
                }

                $normalizedBaseUrl = null;
            }

            $apiKeyRecord = $user->apiKeys()->updateOrCreate(
                ['agent_name' => $agentName, 'provider' => $providerId],
                [
                    'api_key' => $normalizedApiKey,
                    'base_url' => $normalizedBaseUrl,
                ],
            );
            $this->auditLogService->record($user, 'llm.credential.saved', $apiKeyRecord, [
                'agent_name' => $agentName,
                'provider' => $providerId,
                'has_api_key' => $normalizedApiKey !== null,
                'has_custom_base_url' => $normalizedBaseUrl !== null,
            ], $user);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }

        $keyData = [
            'agent_name' => $agentName,
            'provider' => $providerId,
            'configured' => $apiKeyRecord->api_key !== null || $apiKeyRecord->base_url !== null,
            'masked_key' => $apiKeyRecord->maskedKey(),
            'custom_base_url' => $apiKeyRecord->base_url,
        ];

        return $this->success($keyData, UPDATED_SUCCESSFULLY);
    }

    public function deleteApiKey(User $user, string $agentName, string $providerId): JsonResponse
    {
        try {
            $this->agentRegistry->activeSpec($agentName);
            $this->catalog->provider($providerId);
            $deleted = $user->apiKeys()
                ->where('agent_name', $agentName)
                ->where('provider', $providerId)
                ->delete();
            if ($deleted > 0) {
                $this->auditLogService->record($user, 'llm.credential.deleted', null, [
                    'agent_name' => $agentName,
                    'provider' => $providerId,
                ], $user);
            }
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }

        return $this->success(['agent_name' => $agentName, 'provider' => $providerId], DELETED_SUCCESSFULLY);
    }

    public function deleteLegacyApiKey(User $user, string $providerId): JsonResponse
    {
        try {
            $this->catalog->provider($providerId);
            $deleted = $user->apiKeys()
                ->whereNull('agent_name')
                ->where('provider', $providerId)
                ->delete();
            if ($deleted > 0) {
                $this->auditLogService->record($user, 'llm.credential.legacy_deleted', null, [
                    'provider' => $providerId,
                ], $user);
            }
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }

        return $this->success(['provider' => $providerId], DELETED_SUCCESSFULLY);
    }

    public function testConnection(
        User $user,
        ?string $providerId = null,
        ?string $modelId = null,
        string $agentName = 'Orchestrator',
    ): JsonResponse {
        try {
            $this->agentRegistry->activeSpec($agentName);
            $settings = $user->llmSettings;

            if ($settings === null) {
                throw new InvalidArgumentException('Save provider settings and approve a model before testing the connection.');
            }

            $providerId ??= $settings->provider;
            $modelId ??= $settings->model;
            $approvedModels = $settings->approved_models ?? [];
            $this->assertApprovedModel($approvedModels, $providerId, $modelId);
            $this->catalog->model($providerId, $modelId);
            $providerKey = $user->apiKeys()
                ->where('agent_name', $agentName)
                ->where('provider', $providerId)
                ->first();

            if ($providerKey === null) {
                throw new InvalidArgumentException("Save {$agentName}'s credential for this provider before testing the connection.");
            }

            $provider = $this->providerFactory->create(
                $providerId,
                $modelId,
                $providerKey->api_key,
                $providerKey->base_url,
                $user,
            );
            $provider->chat(
                [['role' => 'user', 'content' => 'Reply with OK.']],
                [],
                ['model' => $modelId, 'max_tokens' => 8, 'temperature' => 0],
            );
            $modelCapabilities = $this->catalog->model($providerId, $modelId);
            $hasUnverifiedCapabilities = $modelCapabilities['capabilities_unverified'] ?? false;
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 502);
        }

        $connectionData = [
            'provider' => $providerId,
            'model' => $modelId,
            'connected' => true,
            'capabilities' => $modelCapabilities,
            'warning' => $hasUnverifiedCapabilities
                ? 'Model capabilities are unverified. Tool and vision support are disabled until configured.'
                : null,
        ];

        return $this->success($connectionData);
    }

    /**
     * @param  array<int, array{provider: string, model: string}>  $approvedModels
     * @return array<int, array{provider: string, model: string}>
     */
    private function normalizeApprovedModels(array $approvedModels): array
    {
        $normalizedModels = [];

        foreach ($approvedModels as $approvedModel) {
            $providerId = $approvedModel['provider'];
            $modelId = $approvedModel['model'];
            $this->catalog->model($providerId, $modelId);
            $modelKey = "{$providerId}:{$modelId}";
            $normalizedModels[$modelKey] = [
                'provider' => $providerId,
                'model' => $modelId,
            ];
        }

        return array_values($normalizedModels);
    }

    /**
     * @param  array<int, array{provider: string, model: string}>  $approvedModels
     */
    private function assertApprovedModel(array $approvedModels, string $providerId, string $modelId): void
    {
        if (! $this->isApprovedModel($approvedModels, $providerId, $modelId)) {
            throw new InvalidArgumentException("Model [{$modelId}] is not approved for provider [{$providerId}].");
        }
    }

    /**
     * @param  array<int, array{provider: string, model: string}>  $approvedModels
     */
    private function isApprovedModel(array $approvedModels, string $providerId, string $modelId): bool
    {
        $approvedModelKey = "{$providerId}:{$modelId}";
        $approvedModelKeys = array_map(
            static fn (array $approvedModel): string => "{$approvedModel['provider']}:{$approvedModel['model']}",
            $approvedModels,
        );

        return in_array($approvedModelKey, $approvedModelKeys, true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $modelPricing
     * @param  array<int, array{provider: string, model: string}>  $approvedModels
     * @return array<int, array<string, mixed>>
     */
    private function retainApprovedModelPricing(array $modelPricing, array $approvedModels): array
    {
        return array_values(array_filter(
            $modelPricing,
            fn (array $pricing): bool => $this->isApprovedModel(
                $approvedModels,
                $pricing['provider'],
                $pricing['model'],
            ),
        ));
    }

    /**
     * @param  array<string, array{provider: string, model: string}>  $agentModels
     * @param  array<int, array{provider: string, model: string}>  $approvedModels
     * @return array<string, array{provider: string, model: string}>
     */
    private function normalizeAgentModels(array $agentModels, array $approvedModels): array
    {
        $normalizedModels = [];

        foreach ($agentModels as $agentName => $modelChoice) {
            if (! in_array($agentName, self::SUPPORTED_AGENTS, true)) {
                throw new InvalidArgumentException("Agent [{$agentName}] is not available for Brain routing.");
            }

            $providerId = $modelChoice['provider'];
            $modelId = $modelChoice['model'];
            $this->assertApprovedModel($approvedModels, $providerId, $modelId);
            $normalizedModels[$agentName] = [
                'provider' => $providerId,
                'model' => $modelId,
            ];
        }

        return $normalizedModels;
    }

    /**
     * @param  array<string, string>  $agentBrains
     * @return array<string, string>
     */
    private function normalizeAgentBrains(array $agentBrains): array
    {
        $normalizedBrains = [];

        foreach ($agentBrains as $agentName => $brainMode) {
            if (! in_array($agentName, self::SUPPORTED_AGENTS, true)) {
                throw new InvalidArgumentException("Agent [{$agentName}] is not available for Brain routing.");
            }

            $normalizedBrains[$agentName] = $brainMode;
        }

        return $normalizedBrains;
    }

    /**
     * @param  array<int, array<string, mixed>>  $modelPricing
     * @param  array<int, array{provider: string, model: string}>  $approvedModels
     * @return array<int, array{provider: string, model: string, currency: string, input_cost_per_million: float, output_cost_per_million: float}>
     */
    private function normalizeModelPricing(array $modelPricing, array $approvedModels): array
    {
        $normalizedPricing = [];

        foreach ($modelPricing as $pricing) {
            $providerId = $pricing['provider'];
            $modelId = $pricing['model'];
            $this->assertApprovedModel($approvedModels, $providerId, $modelId);
            $pricingKey = "{$providerId}:{$modelId}";

            if (isset($normalizedPricing[$pricingKey])) {
                throw new InvalidArgumentException("Pricing for model [{$modelId}] is duplicated.");
            }

            $normalizedPricing[$pricingKey] = [
                'provider' => $providerId,
                'model' => $modelId,
                'currency' => strtoupper($pricing['currency']),
                'input_cost_per_million' => (float) $pricing['input_cost_per_million'],
                'output_cost_per_million' => (float) $pricing['output_cost_per_million'],
            ];
        }

        return array_values($normalizedPricing);
    }
}
