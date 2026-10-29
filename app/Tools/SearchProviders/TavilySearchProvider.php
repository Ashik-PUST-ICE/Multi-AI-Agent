<?php

namespace App\Tools\SearchProviders;

use App\Tools\SearchProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TavilySearchProvider implements SearchProviderInterface
{
    public function search(string $query, string $apiKey): array
    {
        $response = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting()
            ->post('https://api.tavily.com/search', [
                'api_key' => $apiKey,
                'query' => $query,
                'max_results' => 5,
                'include_answer' => false,
                'include_raw_content' => false,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Configured search provider returned HTTP '.$response->status().'.');
        }

        $results = $response->json('results');
        if (! is_array($results)) {
            throw new RuntimeException('Configured search provider returned an invalid response.');
        }

        return array_values(array_filter(array_map(
            static fn (array $result): ?array => BraveSearchProvider::normalizeForSearchResult(
                $result['title'] ?? null,
                $result['url'] ?? null,
                $result['content'] ?? null,
                $result['published_date'] ?? null,
            ),
            array_slice(array_filter($results, 'is_array'), 0, 5),
        )));
    }
}
