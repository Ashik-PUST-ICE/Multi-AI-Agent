<?php

namespace App\Llm;

final class OpenAiEmbeddingAdapter extends AbstractHttpEmbeddingProvider
{
    public function embed(array $texts, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        unset($taskType);
        $response = $this->post('/embeddings', [
            'Authorization' => 'Bearer '.$this->requireApiKey(),
        ], [
            'model' => $this->model,
            'input' => $texts,
        ]);
        $items = $response['data'] ?? null;
        if (! is_array($items) || count($items) !== count($texts)) {
            throw new ProviderRequestException('The embedding provider returned an invalid response.', false);
        }

        usort($items, static fn (array $left, array $right): int => ($left['index'] ?? 0) <=> ($right['index'] ?? 0));
        $vectors = [];
        foreach ($items as $item) {
            $vectors[] = $this->normalizeVector($item['embedding'] ?? null);
        }

        return $vectors;
    }

    /**
     * @param  mixed  $vector
     * @return list<float>
     */
    private function normalizeVector(mixed $vector): array
    {
        if (! is_array($vector) || $vector === []) {
            throw new ProviderRequestException('The embedding provider returned an invalid vector.', false);
        }

        foreach ($vector as $value) {
            if (! is_numeric($value)) {
                throw new ProviderRequestException('The embedding provider returned an invalid vector.', false);
            }
        }

        return array_map(static fn ($value): float => (float) $value, array_values($vector));
    }
}
