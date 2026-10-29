<?php

namespace App\Llm;

use App\Models\User;
use InvalidArgumentException;

final class EmbeddingCatalog
{
    public function __construct(private readonly ProviderCatalog $providers) {}

    /**
     * @return array<string, array{label: string, models: array<string, array{adapter: class-string<TextEmbeddingProvider>}>}>
     */
    public function all(): array
    {
        return [
            ProviderCatalog::OPENAI => [
                'label' => $this->providers->provider(ProviderCatalog::OPENAI)['label'],
                'models' => [
                    'text-embedding-3-small' => ['adapter' => OpenAiEmbeddingAdapter::class],
                    'text-embedding-3-large' => ['adapter' => OpenAiEmbeddingAdapter::class],
                ],
            ],
            ProviderCatalog::GEMINI => [
                'label' => $this->providers->provider(ProviderCatalog::GEMINI)['label'],
                'models' => [
                    'gemini-embedding-001' => ['adapter' => GeminiEmbeddingAdapter::class],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, models: list<string>}>
     */
    public function publicCatalog(): array
    {
        $catalog = [];
        foreach ($this->all() as $providerId => $provider) {
            $catalog[$providerId] = [
                'label' => $provider['label'],
                'models' => array_keys($provider['models']),
            ];
        }

        return $catalog;
    }

    public function create(User $user, string $providerId, string $modelId): TextEmbeddingProvider
    {
        $provider = $this->all()[$providerId] ?? null;
        $model = $provider['models'][$modelId] ?? null;
        if ($model === null) {
            throw new InvalidArgumentException('Choose a supported embedding provider and model.');
        }

        $credentials = $user->apiKeys()->where('provider', $providerId)->first();
        if ($credentials === null || ! is_string($credentials->api_key) || trim($credentials->api_key) === '') {
            throw new InvalidArgumentException('Save the selected provider API key in Brain settings before indexing files.');
        }

        $providerConfig = $this->providers->provider($providerId);
        $adapter = $model['adapter'];

        return app($adapter, [
            'baseUrl' => $providerConfig['base_url'],
            'apiKey' => $credentials->api_key,
            'model' => $modelId,
        ]);
    }
}
