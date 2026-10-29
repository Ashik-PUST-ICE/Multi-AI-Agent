<?php

namespace App\Tools\SearchProviders;

use App\Tools\SearchProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BraveSearchProvider implements SearchProviderInterface
{
    public function search(string $query, string $apiKey): array
    {
        $response = Http::acceptJson()
            ->withHeaders(['X-Subscription-Token' => $apiKey])
            ->connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting()
            ->get('https://api.search.brave.com/res/v1/web/search', [
                'q' => $query,
                'count' => 5,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Configured search provider returned HTTP '.$response->status().'.');
        }

        $results = $response->json('web.results');
        if (! is_array($results)) {
            throw new RuntimeException('Configured search provider returned an invalid response.');
        }

        return array_values(array_filter(array_map(
            static fn (array $result): ?array => self::normalizeForSearchResult(
                $result['title'] ?? null,
                $result['url'] ?? null,
                $result['description'] ?? null,
                $result['page_age'] ?? null,
            ),
            array_slice(array_filter($results, 'is_array'), 0, 5),
        )));
    }

    /**
     * @return array{title: string, url: string, snippet: string, published_at: ?string}|null
     */
    public static function normalizeForSearchResult(mixed $title, mixed $url, mixed $snippet, mixed $publishedAt): ?array
    {
        if (! is_string($title) || ! is_string($url) || ! is_string($snippet)) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_contains($host, ':')
            || isset($parts['port']) && (int) $parts['port'] !== 443
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || ! str_contains($host, '.')
            || in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return null;
        }

        $title = self::stripActiveMarkup($title);
        $snippet = self::stripActiveMarkup($snippet);

        return [
            'title' => mb_substr(trim(strip_tags($title)), 0, 240),
            'url' => $url,
            'snippet' => mb_substr(trim(strip_tags($snippet)), 0, 1200),
            'published_at' => is_string($publishedAt) ? mb_substr($publishedAt, 0, 80) : null,
        ];
    }

    private static function stripActiveMarkup(string $value): string
    {
        return preg_replace(
            '~<(script|style|iframe|frame|object|embed|svg|noscript)\b[^>]*>.*?</\1\s*>~is',
            ' ',
            $value,
        ) ?? $value;
    }
}
