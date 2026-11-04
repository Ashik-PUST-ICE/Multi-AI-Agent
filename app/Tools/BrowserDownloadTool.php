<?php

namespace App\Tools;

use App\Services\SafeSandboxService;
use InvalidArgumentException;
use LogicException;

class BrowserDownloadTool implements ContextualToolInterface
{
    public function name(): string
    {
        return 'browser_download';
    }

    public function description(): string
    {
        return 'Download a file in the isolated browser sandbox only after exact owner approval.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function minimumTrustLevel(): int
    {
        return AGENT_TRUST_LEVEL_OUTBOUND;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'HTTPS URL of the resource to download.', 'maxLength' => 2048],
                'filename' => ['type' => 'string', 'description' => 'Suggested local filename inside the isolated quarantine.', 'maxLength' => 255],
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
        throw new LogicException('Live browser downloads require an authenticated agent execution context.');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function executeWithContext(array $input, ToolExecutionContext $context): array
    {
        $url = $input['url'] ?? null;
        if (! is_string($url) || trim($url) === '') {
            throw new InvalidArgumentException('A valid download URL is required.');
        }

        return app(SafeSandboxService::class)->runBrowser($context->user, $url, [
            'action' => 'download',
            'filename' => $input['filename'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function simulate(array $input): array
    {
        $url = $input['url'] ?? null;
        if (! is_string($url) || trim($url) === '') {
            throw new InvalidArgumentException('A valid download URL is required.');
        }

        return [
            'simulated' => true,
            'url' => trim($url),
            'status' => 'not_run',
            'notice' => 'Sandbox simulation only; no file was downloaded.',
        ];
    }
}
