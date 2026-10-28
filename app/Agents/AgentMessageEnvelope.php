<?php

namespace App\Agents;

use InvalidArgumentException;

final class AgentMessageEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly int $taskId,
        public readonly string $type,
        public readonly array $payload,
        public readonly int $schemaVersion = AGENT_MESSAGE_SCHEMA_VERSION,
    ) {
        if ($this->from === '' || $this->to === '' || $this->type === '' || $this->taskId < 1) {
            throw new InvalidArgumentException('Agent message envelope metadata is invalid.');
        }

        if ($this->schemaVersion !== AGENT_MESSAGE_SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported agent message schema version [{$this->schemaVersion}].");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $requiredFields = ['from', 'to', 'task_id', 'type', 'payload', 'schema_version'];

        foreach ($requiredFields as $requiredField) {
            if (! array_key_exists($requiredField, $data)) {
                throw new InvalidArgumentException("Agent message envelope is missing [{$requiredField}].");
            }
        }

        if (array_diff(array_keys($data), $requiredFields) !== []) {
            throw new InvalidArgumentException('Agent message envelope contains unsupported fields.');
        }

        if (! is_string($data['from'])
            || ! is_string($data['to'])
            || ! is_int($data['task_id'])
            || ! is_string($data['type'])
            || ! is_array($data['payload'])
            || ! is_int($data['schema_version'])) {
            throw new InvalidArgumentException('Agent message envelope fields have invalid types.');
        }

        return new self(
            $data['from'],
            $data['to'],
            $data['task_id'],
            $data['type'],
            $data['payload'],
            $data['schema_version'],
        );
    }

    /**
     * @return array{from: string, to: string, task_id: int, type: string, payload: array<string, mixed>, schema_version: int}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'task_id' => $this->taskId,
            'type' => $this->type,
            'payload' => $this->payload,
            'schema_version' => $this->schemaVersion,
        ];
    }
}
