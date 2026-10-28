<?php

namespace App\Llm;

final class AnthropicAdapter extends AbstractHttpLlmProvider
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
