<?php

namespace App\Llm;

use InvalidArgumentException;

final class ProviderCatalog
{
    public const OPENAI = 'openai';

    public const ANTHROPIC = 'anthropic';

    public const GEMINI = 'gemini';

    public const DEEPSEEK = 'deepseek';

    public const OPENAI_COMPATIBLE = 'openai_compatible';

    /**
     * Provider identifiers, models, capabilities, and endpoints remain in the LLM layer.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $providers = [
            self::OPENAI => [
                'label' => 'OpenAI',
                'adapter' => OpenAiAdapter::class,
                'response_format' => 'chat_completions',
                'base_url' => 'https://api.openai.com/v1',
                'requires_api_key' => true,
                'custom_base_url' => false,
                'models' => [
                    'gpt-4o-mini' => ['tools' => true, 'vision' => true, 'context_window' => 128000, 'streaming' => true, 'json_mode' => true],
                    'gpt-4o' => ['tools' => true, 'vision' => true, 'context_window' => 128000, 'streaming' => true, 'json_mode' => true],
                    'gpt-4.1-mini' => ['tools' => true, 'vision' => true, 'context_window' => 1047576, 'streaming' => true, 'json_mode' => true],
                ],
            ],
            self::ANTHROPIC => [
                'label' => 'Anthropic',
                'adapter' => AnthropicAdapter::class,
                'response_format' => 'messages',
                'base_url' => 'https://api.anthropic.com',
                'requires_api_key' => true,
                'custom_base_url' => false,
                'models' => [
                    'claude-3-5-sonnet-20241022' => ['tools' => true, 'vision' => true, 'context_window' => 200000, 'streaming' => true, 'json_mode' => false],
                    'claude-3-5-haiku-20241022' => ['tools' => true, 'vision' => true, 'context_window' => 200000, 'streaming' => true, 'json_mode' => false],
                ],
            ],
            self::GEMINI => [
                'label' => 'Google Gemini',
                'adapter' => GeminiAdapter::class,
                'response_format' => 'generate_content',
                'base_url' => 'https://generativelanguage.googleapis.com',
                'requires_api_key' => true,
                'custom_base_url' => false,
                'models' => [
                    'gemini-2.0-flash' => ['tools' => true, 'vision' => true, 'context_window' => 1048576, 'streaming' => true, 'json_mode' => true],
                    'gemini-1.5-pro' => ['tools' => true, 'vision' => true, 'context_window' => 2097152, 'streaming' => true, 'json_mode' => true],
                ],
            ],
            self::DEEPSEEK => [
                'label' => 'DeepSeek',
                'adapter' => OpenAiCompatibleAdapter::class,
                'response_format' => 'chat_completions',
                'base_url' => 'https://api.deepseek.com',
                'requires_api_key' => true,
                'custom_base_url' => false,
                'models' => [
                    'deepseek-chat' => ['tools' => true, 'vision' => false, 'context_window' => 64000, 'streaming' => true, 'json_mode' => true],
                    'deepseek-reasoner' => ['tools' => false, 'vision' => false, 'context_window' => 64000, 'streaming' => true, 'json_mode' => false],
                ],
            ],
            self::OPENAI_COMPATIBLE => [
                'label' => 'OpenAI-compatible endpoint',
                'adapter' => OpenAiCompatibleAdapter::class,
                'response_format' => 'chat_completions',
                'base_url' => null,
                'requires_api_key' => false,
                'custom_base_url' => true,
                'models' => [],
            ],
        ];

        return $providers;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicCatalog(): array
    {
        $publicCatalog = [];

        foreach ($this->all() as $providerId => $provider) {
            $publicCatalog[$providerId] = [
                'id' => $providerId,
                'label' => $provider['label'],
                'requires_api_key' => $provider['requires_api_key'],
                'custom_base_url' => $provider['custom_base_url'],
                'models' => $provider['models'],
            ];
        }

        return $publicCatalog;
    }

    /**
     * @return array<string, mixed>
     */
    public function provider(string $providerId): array
    {
        $providers = $this->all();
        $provider = $providers[$providerId] ?? null;

        if ($provider === null) {
            throw new InvalidArgumentException("Unsupported LLM provider [{$providerId}].");
        }

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    public function model(string $providerId, string $modelId): array
    {
        $provider = $this->provider($providerId);
        $model = $provider['models'][$modelId] ?? null;

        if ($model === null && $providerId === self::OPENAI_COMPATIBLE && preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $modelId)) {
            return [
                'tools' => false,
                'vision' => false,
                'context_window' => null,
                'streaming' => false,
                'json_mode' => false,
                'capabilities_unverified' => true,
            ];
        }

        if ($model === null) {
            throw new InvalidArgumentException("Unsupported model [{$modelId}] for provider [{$providerId}].");
        }

        return $model;
    }
}
