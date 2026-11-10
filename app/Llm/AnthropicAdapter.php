<?php

namespace App\Llm;

final class AnthropicAdapter extends AbstractHttpLlmProvider implements StreamingLlmProvider
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

        $systemMessages = [];
        $conversation = [];

        $messageCount = count($messages);

        for ($messageIndex = 0; $messageIndex < $messageCount; $messageIndex++) {
            $message = $messages[$messageIndex];

            if (($message['role'] ?? null) === 'system') {
                $systemMessages[] = (string) ($message['content'] ?? '');

                continue;
            }

            if (($message['role'] ?? null) === 'tool') {
                $toolResults = [];

                while ($messageIndex < $messageCount && ($messages[$messageIndex]['role'] ?? null) === 'tool') {
                    $toolResults[] = $this->formatToolResult($messages[$messageIndex]);
                    $messageIndex++;
                }

                $messageIndex--;
                $conversation[] = ['role' => 'user', 'content' => $toolResults];

                continue;
            }

            $conversation[] = $this->formatMessage($message);
        }

        $request = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? config('llm.max_tokens'),
            'messages' => $conversation,
        ];

        if ($systemMessages !== []) {
            $request['system'] = implode("\n\n", $systemMessages);
        }

        if ($tools !== []) {
            $request['tools'] = $this->formatTools($tools);
        }

        $apiKey = $this->requireApiKey();
        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ];
        $response = $this->post('/v1/messages', $headers, $request);

        return $this->normalizer->fromAnthropic($response);
    }

    public function streamChat(
        array $messages,
        array $tools = [],
        array $options = [],
        ?callable $onTextChunk = null,
    ): ChatResponse {
        $model = $this->requireModel($options);
        $systemMessages = [];
        $conversation = [];
        $messageCount = count($messages);

        for ($messageIndex = 0; $messageIndex < $messageCount; $messageIndex++) {
            $message = $messages[$messageIndex];

            if (($message['role'] ?? null) === 'system') {
                $systemMessages[] = (string) ($message['content'] ?? '');

                continue;
            }

            if (($message['role'] ?? null) === 'tool') {
                $toolResults = [];
                while ($messageIndex < $messageCount && ($messages[$messageIndex]['role'] ?? null) === 'tool') {
                    $toolResults[] = $this->formatToolResult($messages[$messageIndex]);
                    $messageIndex++;
                }
                $messageIndex--;
                $conversation[] = ['role' => 'user', 'content' => $toolResults];

                continue;
            }

            $conversation[] = $this->formatMessage($message);
        }

        $request = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? config('llm.max_tokens'),
            'messages' => $conversation,
            'stream' => true,
        ];
        if ($systemMessages !== []) {
            $request['system'] = implode("\n\n", $systemMessages);
        }
        if ($tools !== []) {
            $request['tools'] = $this->formatTools($tools);
        }

        $headers = [
            'x-api-key' => $this->requireApiKey(),
            'anthropic-version' => '2023-06-01',
        ];
        $contentBlocks = [];
        $partialArguments = [];
        $usage = [];
        $stopReason = 'unknown';

        $this->streamPost('/v1/messages', $headers, $request, function (array $event) use (&$contentBlocks, &$partialArguments, &$usage, &$stopReason, $onTextChunk): void {
            $type = $event['type'] ?? null;

            if ($type === 'message_start') {
                $usage['input_tokens'] = $event['message']['usage']['input_tokens'] ?? null;
            } elseif ($type === 'content_block_start' && is_array($event['content_block'] ?? null)) {
                $index = (int) ($event['index'] ?? 0);
                $contentBlocks[$index] = $event['content_block'];
            } elseif ($type === 'content_block_delta' && is_array($event['delta'] ?? null)) {
                $index = (int) ($event['index'] ?? 0);
                $delta = $event['delta'];

                if (($delta['type'] ?? null) === 'text_delta' && is_string($delta['text'] ?? null)) {
                    $contentBlocks[$index] ??= ['type' => 'text', 'text' => ''];
                    $contentBlocks[$index]['text'] = ($contentBlocks[$index]['text'] ?? '').$delta['text'];
                    if ($delta['text'] !== '') {
                        $onTextChunk !== null && $onTextChunk($delta['text']);
                    }
                } elseif (($delta['type'] ?? null) === 'input_json_delta' && is_string($delta['partial_json'] ?? null)) {
                    $partialArguments[$index] = ($partialArguments[$index] ?? '').$delta['partial_json'];
                }
            } elseif ($type === 'message_delta') {
                $stopReason = is_string($event['delta']['stop_reason'] ?? null)
                    ? $event['delta']['stop_reason']
                    : $stopReason;
                if (isset($event['usage']['output_tokens'])) {
                    $usage['output_tokens'] = $event['usage']['output_tokens'];
                }
            }
        });

        foreach ($contentBlocks as $index => &$block) {
            if (($block['type'] ?? null) === 'tool_use' && isset($partialArguments[$index])) {
                try {
                    $block['input'] = json_decode($partialArguments[$index], true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new ProviderRequestException(
                        'The LLM provider returned invalid streamed tool arguments.',
                        false,
                        $exception,
                    );
                }
            }
        }
        unset($block);

        return $this->normalizer->fromAnthropic([
            'content' => array_values($contentBlocks),
            'usage' => $usage,
            'stop_reason' => $stopReason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function formatMessage(array $message): array
    {
        $role = $message['role'] ?? 'user';
        $content = $message['content'] ?? '';

        if ($role === 'assistant' && isset($message['tool_calls'])) {
            $content = $this->formatAssistantToolCalls($message);
        } elseif (is_array($message['images'] ?? null) && $message['images'] !== []) {
            $blocks = [];
            if (is_string($content) && $content !== '') {
                $blocks[] = ['type' => 'text', 'text' => $content];
            }
            foreach ($message['images'] as $image) {
                if (! is_array($image)
                    || ! is_string($image['mime_type'] ?? null)
                    || ! is_string($image['data'] ?? null)) {
                    throw new \InvalidArgumentException('A user image attachment has an invalid format.');
                }

                $blocks[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $image['mime_type'],
                        'data' => $image['data'],
                    ],
                ];
            }
            $content = $blocks;
        } else {
            unset($message['images']);
        }

        return [
            'role' => $role === 'assistant' ? 'assistant' : 'user',
            'content' => $content,
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function formatToolResult(array $message): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $message['tool_call_id'] ?? '',
            'content' => $message['content'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<int, array<string, mixed>>
     */
    private function formatAssistantToolCalls(array $message): array
    {
        $blocks = [];
        $text = $message['content'] ?? null;

        if (is_string($text) && $text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }

        foreach ($message['tool_calls'] as $toolCall) {
            $function = $toolCall['function'] ?? $toolCall;
            $rawArguments = $function['arguments'] ?? [];
            $arguments = is_string($rawArguments) ? json_decode($rawArguments, true, 512, JSON_THROW_ON_ERROR) : $rawArguments;
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $toolCall['id'] ?? '',
                'name' => $function['name'] ?? '',
                'input' => $arguments,
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function formatTools(array $tools): array
    {
        $formattedTools = [];

        foreach ($tools as $tool) {
            $formattedTools[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['input_schema'],
            ];
        }

        return $formattedTools;
    }
}
