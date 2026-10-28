<?php

namespace App\Llm;

class OpenAiAdapter extends AbstractHttpLlmProvider
{
    public function __construct(
        string $baseUrl,
        ?string $apiKey,
        private readonly ResponseNormalizer $normalizer,
    ) {
        parent::__construct($baseUrl, $apiKey);
    }

    public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
    {
        $model = $this->requireModel($options);

        $request = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? config('llm.max_tokens'),
        ];
        $temperature = $options['temperature'] ?? null;

        if ($temperature !== null) {
            $request['temperature'] = $temperature;
        }

        if ($tools !== []) {
            $request['tools'] = $this->formatTools($tools);
        }

        $headers = [];

        if ($this->apiKey !== null && trim($this->apiKey) !== '') {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        $response = $this->post('/chat/completions', $headers, $request);

        return $this->normalizer->fromOpenAi($response);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function formatTools(array $tools): array
    {
        $formattedTools = [];

        foreach ($tools as $tool) {
            $formattedTools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'],
                ],
            ];
        }

        return $formattedTools;
    }
}
