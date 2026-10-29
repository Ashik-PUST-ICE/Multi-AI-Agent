<?php

namespace App\Tools;

use App\Services\WebSearchService;
use InvalidArgumentException;
use LogicException;

class WebSearchTool implements ContextualToolInterface
{
    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Search the web for information relevant to a query.';
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
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query.',
                    'maxLength' => 500,
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        throw new LogicException('Live web search requires an authenticated agent execution context.');
    }

    public function executeWithContext(array $input, ToolExecutionContext $context): array
    {
        $rawQuery = $input['query'] ?? null;
        if (! is_string($rawQuery)) {
            throw new InvalidArgumentException('A string search query is required.');
        }

        return app(WebSearchService::class)->search($context->user, $rawQuery);
    }

    public function simulate(array $input): array
    {
        $rawQuery = $input['query'] ?? null;

        if (! is_string($rawQuery)) {
            throw new InvalidArgumentException('A string search query is required.');
        }

        $query = trim($rawQuery);

        if ($query === '') {
            throw new InvalidArgumentException('A non-empty search query is required.');
        }

        return [
            'simulated' => true,
            'query' => $query,
            'results' => [],
            'notice' => 'Sandbox simulation only; no web request was sent.',
        ];
    }
}
