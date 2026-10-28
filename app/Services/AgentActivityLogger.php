<?php

namespace App\Services;

use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;

class AgentActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordMessage(
        ?AgentRun $agentRun,
        string $role,
        string $content,
        array $metadata = [],
    ): void {
        if ($agentRun === null) {
            return;
        }

        DB::transaction(function () use ($agentRun, $role, $content, $metadata): void {
            $messageSequence = (int) $agentRun->messages()->max('sequence') + 1;
            $eventSequence = (int) $agentRun->runEvents()->max('sequence') + 1;
            $safeContent = $this->redactCredentials($content);
            $safeMetadata = $this->redactValue($metadata);

            $message = $agentRun->messages()->create([
                'task_id' => $agentRun->task_id,
                'mode' => $agentRun->mode,
                'role' => $role,
                'content' => $safeContent,
                'sequence' => $messageSequence,
                'metadata' => $safeMetadata,
            ]);

            $agentRun->runEvents()->create([
                'task_id' => $agentRun->task_id,
                'event_type' => 'agent.message',
                'mode' => $agentRun->mode,
                'sequence' => $eventSequence,
                'payload' => [
                    'message_id' => $message->id,
                    'role' => $role,
                    'metadata' => $safeMetadata,
                ],
                'occurred_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(?AgentRun $agentRun, string $eventType, array $payload = []): void
    {
        if ($agentRun === null) {
            return;
        }

        $eventSequence = (int) $agentRun->runEvents()->max('sequence') + 1;

        $agentRun->runEvents()->create([
            'task_id' => $agentRun->task_id,
            'event_type' => $eventType,
            'mode' => $agentRun->mode,
            'sequence' => $eventSequence,
            'payload' => $this->redactValue($payload),
            'occurred_at' => now(),
        ]);
    }

    private function redactCredentials(string $content): string
    {
        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i',
            '/\bsk-[A-Za-z0-9_-]{12,}/',
            '/\b(api[_-]?key|access[_-]?token|password)\s*[:=]\s*["\']?[^,\s"\']+/i',
        ];

        return preg_replace($patterns, '[REDACTED]', $content) ?? $content;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function redactValue(array $value): array
    {
        foreach ($value as $key => $item) {
            $sensitiveKey = preg_match('/api[_-]?key|access[_-]?token|password|secret/i', (string) $key) === 1;

            if ($sensitiveKey && $item !== null) {
                $value[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->redactValue($item);

                continue;
            }

            if (is_string($item)) {
                $value[$key] = $this->redactCredentials($item);

                continue;
            }
        }

        return $value;
    }
}
