<?php

namespace App\Llm;

final class GeminiEmbeddingAdapter extends AbstractHttpEmbeddingProvider
{
    public function embed(array $texts, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        $requests = array_map(static fn (string $text): array => [
            'model' => 'models/'.$this->model,
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => $taskType,
        ], $texts);
        $response = $this->post(
            '/v1beta/models/'.rawurlencode($this->model).':batchEmbedContents',
            ['x-goog-api-key' => $this->requireApiKey()],
            ['requests' => $requests],
        );
        $items = $response['embeddings'] ?? null;
        if (! is_array($items) || count($items) !== count($texts)) {
            throw new ProviderRequestException('The embedding provider returned an invalid response.', false);
        }

        $vectors = [];
        foreach ($items as $item) {
            $values = $item['values'] ?? null;
            if (! is_array($values) || $values === []) {
                throw new ProviderRequestException('The embedding provider returned an invalid vector.', false);
            }
            foreach ($values as $value) {
                if (! is_numeric($value)) {
                    throw new ProviderRequestException('The embedding provider returned an invalid vector.', false);
                }
            }
            $vectors[] = array_map(static fn ($value): float => (float) $value, array_values($values));
        }

        return $vectors;
    }
}
