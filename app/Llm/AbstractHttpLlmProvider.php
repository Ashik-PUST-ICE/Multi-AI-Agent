<?php

namespace App\Llm;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
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
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): void  $onEvent
     */
    protected function streamPost(string $path, array $headers, array $payload, callable $onEvent): void
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        $response = Http::accept('text/event-stream')
            ->asJson()
            ->withHeaders($headers)
            ->withOptions(['allow_redirects' => false, 'stream' => true])
            ->connectTimeout(config('llm.connect_timeout'))
            ->timeout(config('llm.timeout'))
            ->post($url, $payload);

        if (! $response->successful()) {
            $this->decodeResponse($response);
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';

        try {
            while (! $stream->eof()) {
                $buffer .= $stream->read(4096);
                $buffer = str_replace("\r\n", "\n", $buffer);

                while (preg_match('/\n\n/', $buffer, $matches, PREG_OFFSET_CAPTURE) === 1) {
                    $boundary = $matches[0][1];
                    $frame = substr($buffer, 0, $boundary);
                    $buffer = substr($buffer, $boundary + strlen($matches[0][0]));
                    $this->dispatchSseFrame($frame, $onEvent);
                }
            }

            if (trim($buffer) !== '') {
                $this->dispatchSseFrame($buffer, $onEvent);
            }
        } finally {
            $stream->close();
        }
    }

    /**
     * @param  callable(array<string, mixed>): void  $onEvent
     */
    private function dispatchSseFrame(string $frame, callable $onEvent): void
    {
        $data = [];
        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5));
            }
        }

        if ($data === []) {
            return;
        }

        $payload = implode("\n", $data);
        if ($payload === '[DONE]') {
            return;
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderRequestException(
                'The LLM provider returned an invalid streaming response.',
                false,
                $exception,
            );
        }

        if (! is_array($event)) {
            throw new ProviderRequestException(
                'The LLM provider returned an invalid streaming response.',
                false,
                new RuntimeException('LLM provider stream event must be a JSON object.'),
            );
        }

        if (isset($event['error'])) {
            throw new ProviderRequestException(
                'The LLM provider returned an error while streaming.',
                false,
                new RuntimeException('LLM provider stream reported an error.'),
            );
        }

        $onEvent($event);
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
