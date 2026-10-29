<?php

namespace App\Tools;

interface SearchProviderInterface
{
    /**
     * @return list<array{title: string, url: string, snippet: string, published_at: ?string}>
     */
    public function search(string $query, string $apiKey): array;
}
