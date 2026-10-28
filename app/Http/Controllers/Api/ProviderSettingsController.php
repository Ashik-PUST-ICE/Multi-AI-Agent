<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SaveProviderApiKeyRequest;
use App\Http\Requests\Api\TestProviderConnectionRequest;
use App\Http\Requests\Api\UpdateProviderSettingsRequest;
use App\Services\ProviderSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderSettingsController extends Controller
{
    public function __construct(
        private readonly ProviderSettingsService $providerSettingsService,
    ) {}

    public function catalog(): JsonResponse
    {
        return $this->providerSettingsService->getCatalog();
    }

    public function index(Request $request): JsonResponse
    {
        return $this->providerSettingsService->getSettings($request->user());
    }

    public function update(UpdateProviderSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        $validatedData = $request->validated();

        return $this->providerSettingsService->saveSettings($user, $validatedData);
    }

    public function saveKey(SaveProviderApiKeyRequest $request, string $provider): JsonResponse
    {
        $user = $request->user();
        $validatedData = $request->validated();
        $apiKeyValue = $validatedData['api_key'] ?? null;
        $baseUrl = $validatedData['base_url'] ?? null;

        return $this->providerSettingsService->saveApiKey(
            $user,
            $provider,
            $apiKeyValue,
            $baseUrl,
        );
    }

    public function deleteKey(Request $request, string $provider): JsonResponse
    {
        return $this->providerSettingsService->deleteApiKey($request->user(), $provider);
    }

    public function testConnection(TestProviderConnectionRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $providerId = $validatedData['provider'] ?? null;
        $modelId = $validatedData['model'] ?? null;

        return $this->providerSettingsService->testConnection(
            $request->user(),
            $providerId,
            $modelId,
        );
    }
}
