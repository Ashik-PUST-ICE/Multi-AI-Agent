<?php

namespace App\Tools;

use App\Services\WebPageReaderService;
use InvalidArgumentException;
use LogicException;

class WebPageReadTool implements ContextualToolInterface
{
    public function name(): string
    {
        return 'web_read';
    }

    public function description(): string
    {
        return 'Read public HTML or plain-text content from an HTTPS domain explicitly allowlisted by the owner.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function minimumTrustLevel(): int
    {
        return AGENT_TRUST_LEVEL_READ;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'An HTTPS URL on an owner-allowlisted public domain.',
                    'maxLength' => 2048,
                ],
            ],
            'required' => ['url'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        throw new LogicException('Live page reading requires an authenticated agent execution context.');
    }

    public function executeWithContext(array $input, ToolExecutionContext $context): array
    {
        $url = $input['url'] ?? null;
        if (! is_string($url) || strlen($url) > 2048) {
            throw new InvalidArgumentException('A valid page URL of at most 2048 bytes is required.');
        }

        return app(WebPageReaderService::class)->read($context->user, $url);
    }

    public function simulate(array $input): array
    {
        $url = $input['url'] ?? null;
        if (! is_string($url) || trim($url) === '') {
            throw new InvalidArgumentException('A page URL is required.');
        }

        return [
            'simulated' => true,
            'url' => trim($url),
            'text' => '',
            'notice' => 'Sandbox simulation only; no page was requested.',
        ];
    }
}
