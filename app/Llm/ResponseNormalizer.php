<?php

namespace App\Llm;

use Illuminate\Support\Arr;

final class ResponseNormalizer
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function fromOpenAi(array $response): ChatResponse
    {
        $choice = $response['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $toolCalls = [];

        foreach ($message['tool_calls'] ?? [] as $toolCall) {
            $function = $toolCall['function'] ?? [];
            $toolCalls[] = [
                'id' => (string) ($toolCall['id'] ?? ''),
                'name' => (string) ($function['name'] ?? ''),
                'arguments' => (string) ($function['arguments'] ?? '{}'),
            ];
        }

        return new ChatResponse(
            isset($message['content']) ? (string) $message['content'] : null,
            $toolCalls,
            $this->usage($response['usage'] ?? []),
            $toolCalls !== []
                ? 'tool_calls'
                : $this->stopReason((string) ($choice['finish_reason'] ?? 'unknown')),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function fromAnthropic(array $response): ChatResponse
    {
        $textParts = [];
        $toolCalls = [];

        foreach ($response['content'] ?? [] as $contentBlock) {
            if (($contentBlock['type'] ?? null) === 'text') {
                $textParts[] = (string) ($contentBlock['text'] ?? '');
            }

            if (($contentBlock['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id' => (string) ($contentBlock['id'] ?? ''),
                    'name' => (string) ($contentBlock['name'] ?? ''),
                    'arguments' => $contentBlock['input'] ?? [],
                ];
            }
        }

        return new ChatResponse(
            $textParts === [] ? null : implode("\n", $textParts),
            $toolCalls,
            $this->usage([
                'prompt_tokens' => Arr::get($response, 'usage.input_tokens', 0),
                'completion_tokens' => Arr::get($response, 'usage.output_tokens', 0),
            ]),
            $toolCalls !== []
                ? 'tool_calls'
                : $this->stopReason((string) ($response['stop_reason'] ?? 'unknown')),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function fromGemini(array $response): ChatResponse
    {
        $candidate = $response['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];
        $textParts = [];
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $textParts[] = (string) $part['text'];
            }

            if (isset($part['functionCall'])) {
                $functionCall = $part['functionCall'];
                $toolCalls[] = [
                    'id' => (string) ($functionCall['id'] ?? ''),
                    'name' => (string) ($functionCall['name'] ?? ''),
                    'arguments' => $functionCall['args'] ?? [],
                ];
            }
        }

        $usageMetadata = $response['usageMetadata'] ?? [];

        return new ChatResponse(
            $textParts === [] ? null : implode("\n", $textParts),
            $toolCalls,
            $this->usage([
                'prompt_tokens' => $usageMetadata['promptTokenCount'] ?? 0,
                'completion_tokens' => $usageMetadata['candidatesTokenCount'] ?? 0,
                'total_tokens' => $usageMetadata['totalTokenCount'] ?? 0,
            ]),
            $toolCalls !== []
                ? 'tool_calls'
                : $this->stopReason((string) ($candidate['finishReason'] ?? 'unknown')),
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, int|float>
     */
    private function usage(array $usage): array
    {
        $normalized = [];
        $inputTokens = $usage['prompt_tokens'] ?? 0;
        $outputTokens = $usage['completion_tokens'] ?? 0;
        $totalTokens = $usage['total_tokens'] ?? ($inputTokens + $outputTokens);

        if (is_numeric($inputTokens)) {
            $normalized['input_tokens'] = (int) $inputTokens;
        }

        if (is_numeric($outputTokens)) {
            $normalized['output_tokens'] = (int) $outputTokens;
        }

        if (is_numeric($totalTokens)) {
            $normalized['total_tokens'] = (int) $totalTokens;
        }

        return $normalized;
    }

    private function stopReason(string $providerReason): string
    {
        return match (strtolower($providerReason)) {
            'stop', 'stop_sequence', 'end_turn' => 'stop',
            'tool_calls', 'function_call', 'tool_use' => 'tool_calls',
            'length', 'max_tokens' => 'length',
            'content_filter', 'safety', 'recitation' => 'content_filter',
            default => 'unknown',
        };
    }
}
