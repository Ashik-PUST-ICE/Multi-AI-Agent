<?php

namespace Database\Seeders;

use App\Models\AgentSpec;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AgentSpecSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $agentDefinitions = config('agent_specs.agents', []);

        foreach ($agentDefinitions as $agentName => $agentDefinition) {
            $this->seedAgentSpec($agentName, $agentDefinition);
        }
    }

    private function seedAgentSpec(string $agentName, array $definition): void
    {
        $existing = AgentSpec::query()
            ->where('agent_name', $agentName)
            ->where('created_by', null)
            ->orderByDesc('version')
            ->first();

        if ($existing !== null) {
            $this->ensureToolsMatch($existing, $definition);

            return;
        }

        AgentSpec::query()->create([
            'agent_name' => $agentName,
            'version' => 1,
            'status' => AGENT_SPEC_STATUS_ACTIVE,
            'created_by' => null,
            ...$definition,
        ]);
    }

    private function ensureToolsMatch(AgentSpec $existing, array $definition): void
    {
        $desiredTools = $definition['allowed_tools'] ?? [];
        $currentTools = is_array($existing->allowed_tools) ? $existing->allowed_tools : [];

        if (empty(array_diff($desiredTools, $currentTools))) {
            return;
        }

        $now = now();
        $existing->update(['status' => AGENT_SPEC_STATUS_RETIRED, 'updated_at' => $now]);

        AgentSpec::query()->create([
            'agent_name' => $existing->agent_name,
            'version' => (int) AgentSpec::query()->where('agent_name', $existing->agent_name)->max('version') + 1,
            'status' => AGENT_SPEC_STATUS_ACTIVE,
            'role' => $definition['role'] ?? $existing->role,
            'system_prompt' => $definition['system_prompt'] ?? $existing->system_prompt,
            'input_schema' => $definition['input_schema'] ?? $existing->input_schema,
            'output_schema' => $definition['output_schema'] ?? $existing->output_schema,
            'allowed_tools' => $desiredTools,
            'minimum_trust_level' => $definition['minimum_trust_level'] ?? $existing->minimum_trust_level,
            'default_model' => $definition['default_model'] ?? $existing->default_model,
            'fallback_model' => $definition['fallback_model'] ?? $existing->fallback_model,
            'max_steps' => $definition['max_steps'] ?? $existing->max_steps,
            'max_tokens' => $definition['max_tokens'] ?? $existing->max_tokens,
            'timeout_seconds' => $definition['timeout_seconds'] ?? $existing->timeout_seconds,
            'stop_conditions' => $definition['stop_conditions'] ?? $existing->stop_conditions,
            'success_criteria' => $definition['success_criteria'] ?? $existing->success_criteria,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
