<?php

namespace App\Llm;

class OpenAiAdapter extends AbstractHttpLlmProvider implements StreamingLlmProvider
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
            'messages' => $this->formatMessages($messages),
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

    public function streamChat(
        array $messages,
        array $tools = [],
        array $options = [],
        ?callable $onTextChunk = null,
    ): ChatResponse {
        $model = $this->requireModel($options);
        $request = [
            'model' => $model,
            'messages' => $this->formatMessages($messages),
            'max_tokens' => $options['max_tokens'] ?? config('llm.max_tokens'),
            'stream' => true,
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

        $text = '';
        $toolCalls = [];
        $usage = [];
        $finishReason = 'unknown';
        $this->streamPost('/chat/completions', $headers, $request, function (array $event) use (&$text, &$toolCalls, &$usage, &$finishReason, $onTextChunk): void {
            $choice = $event['choices'][0] ?? [];
            $delta = $choice['delta'] ?? [];
            $content = $delta['content'] ?? null;

            if (is_string($content) && $content !== '') {
                $text .= $content;
                $onTextChunk !== null && $onTextChunk($content);
            }

            foreach ($delta['tool_calls'] ?? [] as $toolDelta) {
                $index = (int) ($toolDelta['index'] ?? 0);
                $toolCalls[$index] ??= [
                    'id' => '',
                    'type' => 'function',
                    'function' => ['name' => '', 'arguments' => ''],
                ];

                if (isset($toolDelta['id'])) {
                    $toolCalls[$index]['id'] = $toolDelta['id'];
                }

                $function = $toolDelta['function'] ?? [];
                $toolCalls[$index]['function']['name'] .= (string) ($function['name'] ?? '');
                $toolCalls[$index]['function']['arguments'] .= (string) ($function['arguments'] ?? '');
            }

            if (isset($choice['finish_reason']) && is_string($choice['finish_reason'])) {
                $finishReason = $choice['finish_reason'];
            }

            if (is_array($event['usage'] ?? null)) {
                $usage = $event['usage'];
            }
        });

        return $this->normalizer->fromOpenAi([
            'choices' => [[
                'message' => [
                    'content' => $text,
                    'tool_calls' => array_values($toolCalls),
                ],
                'finish_reason' => $finishReason,
            ]],
            'usage' => $usage,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(array $messages): array
    {
        return array_map(static function (array $message): array {
            $images = $message['images'] ?? [];
            if (! is_array($images) || $images === []) {
                unset($message['images']);

                return $message;
            }

            $content = [];
            if (is_string($message['content'] ?? null) && $message['content'] !== '') {
                $content[] = ['type' => 'text', 'text' => $message['content']];
            }
            foreach ($images as $image) {
                if (! is_array($image)
                    || ! is_string($image['mime_type'] ?? null)
                    || ! is_string($image['data'] ?? null)) {
                    throw new \InvalidArgumentException('A user image attachment has an invalid format.');
                }

                $content[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.$image['mime_type'].';base64,'.$image['data']],
                ];
            }

            return ['role' => $message['role'] ?? 'user', 'content' => $content];
        }, $messages);
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
