<?php

namespace App\Agents;

use App\Models\AgentSpec;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AgentRegistry
{
    public function __construct(
        private readonly SchemaValidator $schemaValidator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function activeSpec(string $agentName): array
    {
        if (! Schema::hasTable('agent_specs')) {
            return $this->configuredDefaultSpec($agentName);
        }

        $agentSpec = AgentSpec::query()
            ->where('agent_name', $agentName)
            ->where('status', AGENT_SPEC_STATUS_ACTIVE)
            ->orderByDesc('version')
            ->first();

        if ($agentSpec !== null) {
            return $this->validateSpec($agentSpec->toArray());
        }

        $hasStoredVersions = AgentSpec::query()
            ->where('agent_name', $agentName)
            ->exists();

        if ($hasStoredVersions) {
            throw new RuntimeException("Agent [{$agentName}] has no active specification.");
        }

        return $this->configuredDefaultSpec($agentName);
    }

    /**
     * @return array<string, mixed>
     */
    private function configuredDefaultSpec(string $agentName): array
    {
        $defaultSpecs = config('agent_specs.agents', []);
        $defaultSpec = $defaultSpecs[$agentName] ?? null;

        if (! is_array($defaultSpec)) {
            throw new RuntimeException("Agent [{$agentName}] is not registered.");
        }

        return $this->validateSpec([
            'agent_name' => $agentName,
            'version' => 1,
            'status' => AGENT_SPEC_STATUS_ACTIVE,
            ...$defaultSpec,
        ]);
    }

    public function timeoutSeconds(string $agentName): int
    {
        return max(1, (int) ($this->activeSpec($agentName)['timeout_seconds'] ?? config('llm.agent_timeout', 60)));
    }

    /**
     * @param  array<string, mixed>  $specification
     * @return array<string, mixed>
     */
    private function validateSpec(array $specification): array
    {
        foreach (['input_schema', 'output_schema'] as $schemaField) {
            if (! is_array($specification[$schemaField] ?? null)) {
                throw new RuntimeException("Agent specification is missing [{$schemaField}].");
            }

            if (($specification[$schemaField]['type'] ?? null) !== 'object') {
                throw new RuntimeException("Agent specification [{$schemaField}] must describe an object.");
            }

            $schemaErrors = $this->schemaValidator->definitionErrors($specification[$schemaField]);
            if ($schemaErrors !== []) {
                throw new RuntimeException(
                    "Agent specification [{$schemaField}] is invalid: ".implode('; ', $schemaErrors),
                );
            }
        }

        return $specification;
    }
}
