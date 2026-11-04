<?php

namespace App\Tools;

use App\Services\SafeSandboxService;
use InvalidArgumentException;
use LogicException;

class BrowserSubmitTool implements ContextualToolInterface
{
    public function name(): string
    {
        return 'browser_submit';
    }

    public function description(): string
    {
        return 'Submit a form in an isolated browser sandbox only after explicit owner approval.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function minimumTrustLevel(): int
    {
        return AGENT_TRUST_LEVEL_WRITE;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'HTTPS URL of the form action.', 'maxLength' => 2048],
                'fields' => ['type' => 'object', 'description' => 'Form field values to submit.'],
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
        throw new LogicException('Live browser submits require an authenticated agent execution context.');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function executeWithContext(array $input, ToolExecutionContext $context): array
    {
        $url = $input['url'] ?? null;
        if (! is_string($url) || trim($url) === '') {
            throw new InvalidArgumentException('A valid form URL is required.');
        }

        return app(SafeSandboxService::class)->runBrowser($context->user, $url, [
            'action' => 'submit',
            'fields' => $input['fields'] ?? [],
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
            throw new InvalidArgumentException('A valid form URL is required.');
        }

        return [
            'simulated' => true,
            'url' => trim($url),
            'status' => 'not_run',
            'notice' => 'Sandbox simulation only; no form was submitted.',
        ];
    }
}
