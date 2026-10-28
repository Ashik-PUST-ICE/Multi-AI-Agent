<?php

namespace App\Tools;

use LogicException;

class WebSearchTool implements ToolInterface
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

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query.',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        throw new LogicException('Live web search is disabled until the security phase configures a validated search adapter.');
    }

    public function simulate(array $input): array
    {
        $rawQuery = $input['query'] ?? null;

        if (! is_string($rawQuery)) {
            throw new \InvalidArgumentException('A string search query is required.');
        }

        $query = trim($rawQuery);

        if ($query === '') {
            throw new \InvalidArgumentException('A non-empty search query is required.');
        }

        return [
            'simulated' => true,
            'query' => $query,
            'results' => [],
            'notice' => 'Sandbox simulation only; no web request was sent.',
        ];
    }
}
