<?php

namespace App\Tools;

use App\Services\SafeSandboxService;
use InvalidArgumentException;
use LogicException;

class BrowserReadTool implements ContextualToolInterface
{
    public function name(): string
    {
        return 'browser_read';
    }

    public function description(): string
    {
        return 'Open an allowlisted page in the isolated browser sandbox and return sanitized page text.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function minimumTrustLevel(): int
    {
        return AGENT_TRUST_LEVEL_READ;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'HTTPS URL on an allowlisted public domain.',
                    'maxLength' => 2048,
                ],
            ],
            'required' => ['url'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array
    {
        throw new LogicException('Live browser reads require an authenticated agent execution context.');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function executeWithContext(array $input, ToolExecutionContext $context): array
    {
        $url = $input['url'] ?? null;
        if (! is_string($url) || trim($url) === '') {
            throw new InvalidArgumentException('A valid page URL is required.');
        }

        return app(SafeSandboxService::class)->runBrowser($context->user, $url, ['action' => 'read']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function simulate(array $input): array
    {
        $url = $input['url'] ?? null;
        if (! is_string($url) || trim($url) === '') {
            throw new InvalidArgumentException('A valid page URL is required.');
        }

        return [
            'simulated' => true,
            'url' => trim($url),
            'status' => 'not_run',
            'notice' => 'Sandbox simulation only; no browser request was sent.',
        ];
    }
}
