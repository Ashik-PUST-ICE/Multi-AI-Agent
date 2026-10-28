<?php

namespace App\Llm;

use App\Models\User;
use InvalidArgumentException;

class ProviderFactory
{
    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly ResponseNormalizer $normalizer,
        private readonly CustomBaseUrlPolicy $baseUrlPolicy,
    ) {}

    public function create(
        string $providerId,
        string $modelId,
        ?string $apiKey,
        ?string $customBaseUrl = null,
        ?User $user = null,
    ): LlmProvider {
        $provider = $this->catalog->provider($providerId);
        $this->catalog->model($providerId, $modelId);
        $baseUrl = $customBaseUrl ?? $provider['base_url'];

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new InvalidArgumentException('A custom base URL must be saved for this provider.');
        }

        if ($customBaseUrl !== null) {
            $allowPrivateEndpoints = $user !== null
                && $this->baseUrlPolicy->userMayUsePrivateEndpoints($user);
            $baseUrl = $this->baseUrlPolicy->validate($customBaseUrl, $allowPrivateEndpoints);
        }

        if ($provider['requires_api_key'] && ($apiKey === null || trim($apiKey) === '')) {
            throw new InvalidArgumentException('Save an API key for this provider before using it.');
        }

        $adapterClass = $provider['adapter'];

        return app($adapterClass, [
            'baseUrl' => $baseUrl,
            'apiKey' => $apiKey,
            'normalizer' => $this->normalizer,
        ]);
    }
}
