<?php

namespace App\Services;

use App\Llm\CustomBaseUrlPolicy;
use App\Llm\ProviderCatalog;
use App\Llm\ProviderFactory;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

class ProviderSettingsService
{
    use ResponseTrait;

    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly ProviderFactory $providerFactory,
        private readonly CustomBaseUrlPolicy $baseUrlPolicy,
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

        foreach ($apiKeys as $apiKey) {
            $keyStatuses[$apiKey->provider] = [
                'configured' => $apiKey->api_key !== null,
                'masked' => $apiKey->maskedKey(),
                'custom_base_url' => $apiKey->base_url,
            ];
        }

        $settingsData = [
            'settings' => $settings?->only([
                'provider',
                'model',
                'approved_models',
                'agent_models',
                'fallback_provider',
                'fallback_model',
            ]),
            'keys' => $keyStatuses,
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
            $providerId = $data['provider'];
            $modelId = $data['model'];
            $this->assertApprovedModel($approvedModels, $providerId, $modelId);

            $fallbackProvider = $data['fallback_provider'] ?? null;
            $fallbackModel = $data['fallback_model'] ?? null;

            if ($fallbackProvider !== null && $fallbackModel !== null) {
                $this->assertApprovedModel($approvedModels, $fallbackProvider, $fallbackModel);
            }

            $agentModels = $this->normalizeAgentModels($data['agent_models'] ?? [], $approvedModels);

            $settings = $user->llmSettings()->updateOrCreate([], [
                'provider' => $providerId,
                'model' => $modelId,
                'approved_models' => $approvedModels,
                'agent_models' => $agentModels,
                'fallback_provider' => $fallbackProvider,
                'fallback_model' => $fallbackModel,
            ]);
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
        ]);

        return $this->success(
            ['settings' => $settingsData],
            UPDATED_SUCCESSFULLY,
        );
    }

    public function saveApiKey(User $user, string $providerId, ?string $apiKey, ?string $baseUrl): JsonResponse
    {
        try {
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
                ['provider' => $providerId],
                [
                    'api_key' => $normalizedApiKey,
                    'base_url' => $normalizedBaseUrl,
                ],
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }

        $keyData = [
            'provider' => $providerId,
            'configured' => $apiKeyRecord->api_key !== null,
            'masked_key' => $apiKeyRecord->maskedKey(),
            'custom_base_url' => $apiKeyRecord->base_url,
        ];

        return $this->success($keyData, UPDATED_SUCCESSFULLY);
    }

    public function deleteApiKey(User $user, string $providerId): JsonResponse
    {
        try {
            $this->catalog->provider($providerId);
            $user->apiKeys()->where('provider', $providerId)->delete();
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }

        return $this->success(['provider' => $providerId], DELETED_SUCCESSFULLY);
    }

    public function testConnection(User $user, ?string $providerId = null, ?string $modelId = null): JsonResponse
    {
        try {
            $settings = $user->llmSettings;

            if ($settings === null) {
                throw new InvalidArgumentException('Save provider settings and approve a model before testing the connection.');
            }

            $providerId ??= $settings->provider;
            $modelId ??= $settings->model;
            $approvedModels = $settings->approved_models ?? [];
            $this->assertApprovedModel($approvedModels, $providerId, $modelId);
            $this->catalog->model($providerId, $modelId);
            $providerKey = $user->apiKeys()->where('provider', $providerId)->first();

            if ($providerKey === null) {
                throw new InvalidArgumentException('Save an API key for this provider before testing the connection.');
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
        $approvedModelKey = "{$providerId}:{$modelId}";
        $approvedModelKeys = array_map(
            static fn (array $approvedModel): string => "{$approvedModel['provider']}:{$approvedModel['model']}",
            $approvedModels,
        );
        $isApproved = in_array($approvedModelKey, $approvedModelKeys, true);

        if (! $isApproved) {
            throw new InvalidArgumentException("Model [{$modelId}] is not approved for provider [{$providerId}].");
        }
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
}
