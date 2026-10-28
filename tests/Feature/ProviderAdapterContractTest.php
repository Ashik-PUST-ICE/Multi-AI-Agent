<?php

namespace Tests\Feature;

use App\Llm\ProviderCatalog;
use App\Llm\ProviderFactory;
use App\Llm\ResponseNormalizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class ProviderAdapterContractTest extends TestCase
{
    public function test_configured_adapters_share_the_normalized_chat_contract(): void
    {
        $catalog = app(ProviderCatalog::class);
        $providers = $catalog->all();
        $supportedProviderIds = array_filter(
            array_keys($providers),
            fn (string $providerId): bool => $providers[$providerId]['base_url'] !== null,
        );

        Http::fake(function (Request $request) {
            $requestPath = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with((string) $requestPath, '/messages')) {
                return Http::response($this->responsePayload('messages'));
            }

            if (str_contains((string) $requestPath, ':generateContent')) {
                return Http::response($this->responsePayload('generate_content'));
            }

            return Http::response($this->responsePayload('chat_completions'));
        });

        foreach ($supportedProviderIds as $providerId) {
            $providerDefinition = $catalog->provider($providerId);
            $modelId = array_key_first($providerDefinition['models']);
            $provider = app(ProviderFactory::class)->create(
                $providerId,
                $modelId,
                'contract-test-key',
            );
            $chatResponse = $provider->chat(
                [['role' => 'user', 'content' => 'Test message']],
                [],
                ['model' => $modelId],
            );

            $this->assertSame('Normalized response', $chatResponse->text);
            $this->assertSame(4, $chatResponse->usage['input_tokens']);
            $this->assertSame(2, $chatResponse->usage['output_tokens']);
            $this->assertSame('stop', $chatResponse->stopReason);
            Http::assertSent(function (Request $request) use ($modelId): bool {
                $requestData = $request->data();
                $requestUrl = $request->url();
                $modelInPayload = ($requestData['model'] ?? null) === $modelId;
                $modelInPath = str_contains($requestUrl, rawurlencode($modelId));

                return $request->method() === 'POST'
                    && $request->hasHeader('Accept', 'application/json')
                    && ($modelInPayload || $modelInPath)
                    && ! str_contains($request->url(), 'contract-test-key');
            });
        }
    }

    public function test_provider_tool_calls_normalize_to_the_same_shape(): void
    {
        $catalog = app(ProviderCatalog::class);
        $providers = $catalog->all();
        $supportedProviderIds = array_filter(
            array_keys($providers),
            fn (string $providerId): bool => $providers[$providerId]['base_url'] !== null,
        );
        $toolSchema = [
            'name' => 'sample_lookup',
            'description' => 'Look up a sample.',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['term' => ['type' => 'string']],
                'required' => ['term'],
            ],
        ];

        Http::fake(function (Request $request) {
            $requestPath = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with((string) $requestPath, '/messages')) {
                return Http::response([
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'call-1',
                        'name' => 'sample_lookup',
                        'input' => ['term' => 'alpha'],
                    ]],
                    'stop_reason' => 'tool_use',
                ]);
            }

            if (str_contains((string) $requestPath, ':generateContent')) {
                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [[
                            'functionCall' => [
                                'id' => 'call-1',
                                'name' => 'sample_lookup',
                                'args' => ['term' => 'alpha'],
                            ],
                        ]]],
                        'finishReason' => 'STOP',
                    ]],
                ]);
            }

            return Http::response([
                'choices' => [[
                    'message' => [
                        'tool_calls' => [[
                            'id' => 'call-1',
                            'function' => [
                                'name' => 'sample_lookup',
                                'arguments' => '{"term":"alpha"}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]);
        });

        foreach ($supportedProviderIds as $providerId) {
            $providerDefinition = $catalog->provider($providerId);
            $modelId = array_key_first($providerDefinition['models']);
            $provider = app(ProviderFactory::class)->create($providerId, $modelId, 'contract-test-key');
            $response = $provider->chat([], [$toolSchema], ['model' => $modelId]);

            $this->assertSame('tool_calls', $response->stopReason);
            $this->assertSame([
                [
                    'id' => 'call-1',
                    'name' => 'sample_lookup',
                    'arguments' => $providerDefinition['response_format'] === 'chat_completions'
                        ? '{"term":"alpha"}'
                        : ['term' => 'alpha'],
                ],
            ], $response->toolCalls);
        }
    }

    public function test_missing_provider_usage_stays_missing_for_conservative_fallback_estimation(): void
    {
        $normalizer = app(ResponseNormalizer::class);

        $this->assertSame([], $normalizer->fromOpenAi([
            'choices' => [['message' => ['content' => 'OpenAI response']]],
        ])->usage);
        $this->assertSame([], $normalizer->fromAnthropic([
            'content' => [['type' => 'text', 'text' => 'Anthropic response']],
        ])->usage);
        $this->assertSame([], $normalizer->fromGemini([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Gemini response']]],
            ]],
        ])->usage);
    }

    public function test_provider_errors_do_not_include_response_body_or_credentials(): void
    {
        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $modelId = array_key_first($catalog->provider($providerId)['models']);
        $provider = app(ProviderFactory::class)->create($providerId, $modelId, 'private-test-key');
        Http::fake(fn () => Http::response(['error' => 'private-test-key must not leak'], 401));

        try {
            $provider->chat([], [], ['model' => $modelId]);
            $this->fail('A failed provider request must throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('401', $exception->getMessage());
            $this->assertStringNotContainsString('private-test-key', $exception->getMessage());
        }
    }

    public function test_compatible_endpoints_can_be_used_without_an_api_key(): void
    {
        $catalog = app(ProviderCatalog::class);
        $providerDefinition = null;

        foreach ($catalog->all() as $candidate) {
            if ($candidate['custom_base_url']) {
                $providerDefinition = $candidate;
                break;
            }
        }

        $this->assertNotNull($providerDefinition);
        $adapter = app($providerDefinition['adapter'], [
            'baseUrl' => 'https://compatible.invalid/v1',
            'apiKey' => null,
            'normalizer' => app(ResponseNormalizer::class),
        ]);
        Http::fake(fn () => Http::response([
            'choices' => [[
                'message' => ['content' => 'Compatible response'],
                'finish_reason' => 'stop',
            ]],
        ]));

        $response = $adapter->chat(
            [['role' => 'user', 'content' => 'Test message']],
            [],
            ['model' => 'local-model'],
        );

        $this->assertSame('Compatible response', $response->text);
        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function test_each_adapter_requires_a_model_before_sending_a_request(): void
    {
        $catalog = app(ProviderCatalog::class);
        $adapterClasses = array_unique(array_column($catalog->all(), 'adapter'));

        foreach ($adapterClasses as $adapterClass) {
            $adapter = app($adapterClass, [
                'baseUrl' => 'https://provider.invalid/v1',
                'apiKey' => 'contract-test-key',
                'normalizer' => app(ResponseNormalizer::class),
            ]);

            try {
                $adapter->chat([]);
                $this->fail("The {$adapterClass} must reject requests without a model.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('A model is required for a chat request.', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function responsePayload(string $responseFormat): array
    {
        return match ($responseFormat) {
            'chat_completions' => [
                'choices' => [[
                    'message' => ['content' => 'Normalized response'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
            ],
            'messages' => [
                'content' => [['type' => 'text', 'text' => 'Normalized response']],
                'stop_reason' => 'stop',
                'usage' => ['input_tokens' => 4, 'output_tokens' => 2],
            ],
            'generate_content' => [
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Normalized response']]],
                    'finishReason' => 'stop',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 4,
                    'candidatesTokenCount' => 2,
                    'totalTokenCount' => 6,
                ],
            ],
            default => throw new InvalidArgumentException("Unknown test response format [{$responseFormat}]."),
        };
    }
}
