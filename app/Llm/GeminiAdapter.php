<?php

namespace App\Llm;

use InvalidArgumentException;

final class GeminiAdapter extends AbstractHttpLlmProvider
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

        $systemInstructions = [];
        $contents = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            if ($role === 'system') {
                $systemInstructions[] = ['text' => (string) $content];

                continue;
            }

            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => $this->formatParts($message),
            ];
        }

        $request = ['contents' => $contents];

        if ($systemInstructions !== []) {
            $request['systemInstruction'] = ['parts' => $systemInstructions];
        }

        if ($tools !== []) {
            $request['tools'] = [[
                'functionDeclarations' => $this->formatTools($tools),
            ]];
        }

        $generationConfig = [];
        $maxTokens = $options['max_tokens'] ?? config('llm.max_tokens');
        $temperature = $options['temperature'] ?? null;

        if ($maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $maxTokens;
        }

        if ($temperature !== null) {
            $generationConfig['temperature'] = $temperature;
        }

        if ($generationConfig !== []) {
            $request['generationConfig'] = $generationConfig;
        }

        $apiKey = $this->requireApiKey();
        $path = '/v1beta/models/'.rawurlencode($model).':generateContent';
        $response = $this->post($path, ['x-goog-api-key' => $apiKey], $request);

        return $this->normalizer->fromGemini($response);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<int, array<string, mixed>>
     */
    private function formatParts(array $message): array
    {
        $parts = [];
        $role = $message['role'] ?? 'user';
        $content = $message['content'] ?? '';

        if ($role === 'tool') {
            $toolName = $message['name'] ?? '';
            $toolResponse = json_decode((string) $content, true);
            $parts[] = [
                'functionResponse' => [
                    'name' => $toolName,
                    'response' => is_array($toolResponse) ? $toolResponse : ['content' => $content],
                ],
            ];

            return $parts;
        }

        if (is_string($content) && $content !== '') {
            $parts[] = ['text' => $content];
        }

        foreach ($message['tool_calls'] ?? [] as $toolCall) {
            $function = $toolCall['function'] ?? $toolCall;
            $rawArguments = $function['arguments'] ?? [];
            $arguments = is_string($rawArguments)
                ? json_decode($rawArguments, true, 512, JSON_THROW_ON_ERROR)
                : $rawArguments;

            if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
                throw new InvalidArgumentException('Tool arguments must be a JSON object.');
            }

            $functionCall = [
                'name' => $function['name'] ?? '',
                'args' => $arguments,
            ];
            $callId = $toolCall['id'] ?? null;

            if (is_string($callId) && $callId !== '') {
                $functionCall['id'] = $callId;
            }

            $parts[] = ['functionCall' => $functionCall];
        }

        if ($parts === []) {
            $parts[] = ['text' => ''];
        }

        return $parts;
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
                'parameters' => $tool['input_schema'],
            ];
        }

        return $formattedTools;
    }
}
