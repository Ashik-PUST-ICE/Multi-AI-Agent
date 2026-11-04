<?php

namespace App\Tools;

use App\Services\UserApiIntegrationService;
use InvalidArgumentException;
use LogicException;

class RestRequestTool implements ContextualToolInterface
{
    public function name(): string
    {
        return 'rest_request';
    }

    public function description(): string
    {
        return 'Call an owner-configured REST endpoint using its saved credentials and method scope.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function minimumTrustLevel(): int
    {
        return AGENT_TRUST_LEVEL_OUTBOUND;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'integration_id' => ['type' => 'integer', 'minimum' => 1],
                'method' => ['type' => 'string', 'enum' => ['GET', 'POST']],
                'path' => [
                    'type' => 'string',
                    'maxLength' => 1024,
                    'description' => 'Relative path appended to the saved endpoint; no leading slash or traversal.',
                ],
                'query' => ['type' => 'object', 'additionalProperties' => true],
                'body' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'required' => ['integration_id', 'method', 'path'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        throw new LogicException('REST requests require an authenticated owner context.');
    }

    public function executeWithContext(array $input, ToolExecutionContext $context): array
    {
        if (! is_int($input['integration_id'] ?? null)
            || ! is_string($input['method'] ?? null)
            || ! is_string($input['path'] ?? null)
            || isset($input['query']) && ! is_array($input['query'])
            || isset($input['body']) && ! is_array($input['body'])) {
            throw new InvalidArgumentException('REST call input is invalid.');
        }

        return app(UserApiIntegrationService::class)->request($context->user, [
            ...$input,
            'query' => $input['query'] ?? [],
            'body' => $input['body'] ?? [],
        ]);
    }

    public function simulate(array $input): array
    {
        return [
            'simulated' => true,
            'integration_id' => $input['integration_id'] ?? null,
            'method' => $input['method'] ?? null,
            'path' => $input['path'] ?? null,
            'sent' => false,
            'notice' => 'Sandbox simulation only; no REST request was sent.',
        ];
    }
}
