<?php

namespace App\Llm;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

abstract class AbstractHttpLlmProvider implements LlmProvider
{
    public function __construct(
        protected readonly string $baseUrl,
        protected readonly ?string $apiKey,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function post(string $path, array $headers, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        $connectTimeout = config('llm.connect_timeout');
        $timeout = config('llm.timeout');
        $response = Http::acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->post($url, $payload);

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeResponse(Response $response): array
    {
        if (! $response->successful()) {
            $status = $response->status();
            $retryable = $status === 429 || $status >= 500;

            throw new ProviderRequestException(
                'The LLM provider returned an unsuccessful HTTP response.',
                $retryable,
                new RuntimeException("LLM provider request failed with HTTP status {$status}."),
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderRequestException(
                'The LLM provider returned an invalid response.',
                false,
                new RuntimeException('LLM provider returned an invalid JSON response.'),
            );
        }

        return $body;
    }

    protected function requireApiKey(): string
    {
        if ($this->apiKey === null || trim($this->apiKey) === '') {
            throw new RuntimeException('An API key must be saved in Settings before connecting to this provider.');
        }

        return $this->apiKey;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function requireModel(array $options): string
    {
        $model = $options['model'] ?? null;

        if (! is_string($model) || $model === '') {
            throw new InvalidArgumentException('A model is required for a chat request.');
        }

        return $model;
    }
}
