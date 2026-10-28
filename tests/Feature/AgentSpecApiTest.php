<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\AgentSpecSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentSpecApiTest extends TestCase
{
    use DatabaseMigrations;

    public function test_agent_spec_api_requires_manage_permission(): void
    {
        $this->seed(AgentSpecSeeder::class);

        $this->getJson('/api/v1/admin/agent-specs')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/agent-specs')->assertForbidden();
    }

    public function test_admin_can_create_test_activate_and_rollback_versioned_specs(): void
    {
        $this->seed(AgentSpecSeeder::class);

        $user = User::factory()->create();
        $managePermission = Permission::query()
            ->where('name', 'agent_specs.manage')
            ->firstOrFail();
        $user->givePermissionTo($managePermission);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/agent-specs')
            ->assertOk()
            ->assertJsonCount(4, 'agent_specs');

        $this->postJson('/api/v1/admin/agent-specs/Research/versions', [
            'system_prompt' => 'Research with this reviewed draft prompt.',
        ])->assertCreated()
            ->assertJsonPath('agent_spec.version', 2)
            ->assertJsonPath('agent_spec.status', AGENT_SPEC_STATUS_DRAFT);

        $this->postJson('/api/v1/admin/agent-specs/Research/versions/2/status', [
            'status' => AGENT_SPEC_STATUS_ACTIVE,
        ])->assertStatus(409);

        $this->patchJson('/api/v1/admin/agent-specs/Research/versions/2', [
            'role' => 'Research with evidence and report uncertainty.',
        ])->assertOk()
            ->assertJsonPath('agent_spec.role', 'Research with evidence and report uncertainty.');

        $this->postJson('/api/v1/admin/agent-specs/Research/versions/2/status', [
            'status' => AGENT_SPEC_STATUS_TEST,
        ])->assertOk()
            ->assertJsonPath('agent_spec.status', AGENT_SPEC_STATUS_TEST);

        $this->postJson('/api/v1/admin/agent-specs/Research/versions/2/status', [
            'status' => AGENT_SPEC_STATUS_ACTIVE,
        ])->assertOk()
            ->assertJsonPath('agent_spec.status', AGENT_SPEC_STATUS_ACTIVE);

        $this->assertDatabaseHas('agent_specs', [
            'agent_name' => 'Research',
            'version' => 1,
            'status' => AGENT_SPEC_STATUS_RETIRED,
        ]);

        $this->postJson('/api/v1/admin/agent-specs/Research/versions/1/rollback')
            ->assertCreated()
            ->assertJsonPath('agent_spec.version', 3)
            ->assertJsonPath('agent_spec.status', AGENT_SPEC_STATUS_DRAFT)
            ->assertJsonPath('agent_spec.system_prompt', config('agent_specs.agents.Research.system_prompt'));
    }

    public function test_invalid_agent_spec_schema_cannot_enter_test_lifecycle(): void
    {
        $this->seed(AgentSpecSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::query()->where('name', 'agent_specs.manage')->firstOrFail());
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/admin/agent-specs/Research/versions', [
            'input_schema' => [
                'type' => 'object',
                'required' => ['missing_property'],
                'properties' => [],
            ],
        ])->assertUnprocessable();
    }
}
