<?php

namespace App\Llm;

interface TextEmbeddingProvider
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts, string $taskType): array;
}
