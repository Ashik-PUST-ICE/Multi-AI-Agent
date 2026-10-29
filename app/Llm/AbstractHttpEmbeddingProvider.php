<?php

namespace App\Llm;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class AbstractHttpEmbeddingProvider implements TextEmbeddingProvider
{
    public function __construct(
        protected readonly string $baseUrl,
        protected readonly ?string $apiKey,
        protected readonly string $model,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function post(string $path, array $headers, array $payload): array
    {
        $response = Http::acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout((int) config('llm.connect_timeout'))
            ->timeout((int) config('llm.timeout'))
            ->post(rtrim($this->baseUrl, '/').'/'.ltrim($path, '/'), $payload);

        if (! $response->successful()) {
            $status = $response->status();

            throw new ProviderRequestException(
                'The embedding provider returned an unsuccessful HTTP response.',
                $status === 429 || $status >= 500,
                new RuntimeException("Embedding request failed with HTTP status {$status}."),
            );
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new ProviderRequestException('The embedding provider returned an invalid response.', false);
        }

        return $body;
    }

    protected function requireApiKey(): string
    {
        if ($this->apiKey === null || trim($this->apiKey) === '') {
            throw new RuntimeException('An API key must be saved in Settings before creating embeddings.');
        }

        return $this->apiKey;
    }
}
