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

    public function execute(array $input): array
    {
        throw new LogicException('Live web search is disabled until the security phase configures a validated search adapter.');
    }

    public function simulate(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));

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
