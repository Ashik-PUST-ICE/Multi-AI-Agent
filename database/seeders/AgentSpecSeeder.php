<?php

namespace Database\Seeders;

use App\Models\AgentSpec;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class AgentSpecSeeder extends Seeder
{
    public function run(): void
    {
        Permission::query()->firstOrCreate([
            'name' => 'agent_specs.manage',
            'guard_name' => 'web',
        ], [
            'display_name' => 'Manage agent specifications',
            'module' => 1,
        ]);

        $agentDefinitions = config('agent_specs.agents', []);

        foreach ($agentDefinitions as $agentName => $agentDefinition) {
            AgentSpec::query()->firstOrCreate([
                'agent_name' => $agentName,
                'version' => 1,
            ], [
                'status' => AGENT_SPEC_STATUS_ACTIVE,
                ...$agentDefinition,
            ]);
        }
    }
}
