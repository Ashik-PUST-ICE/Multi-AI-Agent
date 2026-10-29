<?php

namespace Tests\Feature;

use App\Agents\AgentRegistry;
use App\Llm\ProviderCatalog;
use App\Models\AgentPack;
use App\Models\Permission;
use App\Models\User;
use App\Services\AgentPackService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AgentPackApiTest extends TestCase
{
    use DatabaseMigrations;

    public function test_users_can_list_starter_packs_and_manage_only_their_own_installation(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/agent-packs')
            ->assertOk()
            ->assertJsonCount(5, 'packs')
            ->assertJsonPath('packs.0.installed', false);

        $this->putJson('/api/v1/agent-packs/research/installation', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('enabled', true);
        $this->assertDatabaseHas('user_agent_packs', [
            'user_id' => $user->id,
            'status' => USER_AGENT_PACK_STATUS_ENABLED,
        ]);
        $this->assertDatabaseMissing('user_agent_packs', ['user_id' => $otherUser->id]);

        $this->putJson('/api/v1/agent-packs/research/installation', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('enabled', false);
        $this->assertDatabaseHas('user_agent_packs', [
            'user_id' => $user->id,
            'status' => USER_AGENT_PACK_STATUS_DISABLED,
        ]);
    }

    public function test_an_uninstalled_or_disabled_pack_cannot_be_used_for_a_task(): void
    {
        $user = $this->userWithApprovedModel();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Write a concise welcome message.',
            'agent_pack' => 'writing-translation',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('agent_pack');

        $this->putJson('/api/v1/agent-packs/writing-translation/installation', ['enabled' => true])->assertOk();
        $this->putJson('/api/v1/agent-packs/writing-translation/installation', ['enabled' => false])->assertOk();
        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Write a concise welcome message.',
            'agent_pack' => 'writing-translation',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('agent_pack');
    }

    public function test_enabled_pack_guidance_is_stored_for_the_run_without_changing_agent_permissions(): void
    {
        Queue::fake();
        $user = $this->userWithApprovedModel();
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/agent-packs/writing-translation/installation', ['enabled' => true])->assertOk();

        $this->postJson('/api/v1/tasks', [
            'title' => 'Welcome message',
            'prompt' => 'Write a short welcome message.',
            'agent_pack' => 'writing-translation',
        ])->assertAccepted()
            ->assertJsonPath('agent_pack.slug', 'writing-translation')
            ->assertJsonPath('task.agent_pack.version', 1);

        $task = $user->tasks()->firstOrFail();
        $run = $task->agentRuns()->firstOrFail();
        $specification = app(AgentRegistry::class)->activeSpec('Writer');
        $packProfile = $task->input['agent_pack']['profiles']['Writer'];

        $this->assertSame($specification['allowed_tools'], $packProfile['allowed_tools']);
        $this->assertSame($specification['minimum_trust_level'], $packProfile['minimum_trust_level']);
        $this->assertSame($packProfile['guidance'], $run->input['payload']['context']['agent_pack_guidance']);
    }

    public function test_pack_definition_changes_without_a_matching_hash_fail_closed(): void
    {
        $user = User::factory()->create();
        $service = app(AgentPackService::class);
        $service->index($user);
        $pack = AgentPack::query()->where('slug', 'research')->firstOrFail();
        $pack->forceFill([
            'definition' => ['agents' => ['Research'], 'profiles' => [], 'examples' => []],
        ])->saveQuietly();

        $this->expectException(RuntimeException::class);
        $service->index($user);
    }

    public function test_pack_admin_requires_permission_and_can_author_version_and_rollback_custom_packs(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/agent-packs')->assertForbidden();

        $user->givePermissionTo(Permission::query()->where('name', 'agent_packs.manage')->firstOrFail());
        $this->getJson('/api/v1/admin/agent-packs')->assertOk()->assertJsonCount(5, 'packs');

        $definition = [
            'slug' => 'support-writing',
            'name' => 'Support Writing',
            'description' => 'Draft friendly support responses.',
            'agents' => ['Writer'],
            'guidance' => ['Writer' => 'Use empathetic, concise language.'],
            'examples' => ['Write a polite response to a delayed delivery complaint.'],
        ];
        $this->postJson('/api/v1/admin/agent-packs', $definition)
            ->assertCreated()
            ->assertJsonPath('pack.status', AGENT_PACK_STATUS_DRAFT)
            ->assertJsonPath('pack.profiles.Writer.minimum_trust_level', app(AgentRegistry::class)->activeSpec('Writer')['minimum_trust_level']);

        $this->patchJson('/api/v1/admin/agent-packs/support-writing/versions/1', [
            'guidance' => ['Writer' => 'Be empathetic and clear.'],
        ])->assertOk()
            ->assertJsonPath('pack.profiles.Writer.guidance', 'Be empathetic and clear.');

        $this->postJson('/api/v1/admin/agent-packs/support-writing/versions/1/status', ['status' => AGENT_PACK_STATUS_ACTIVE])
            ->assertOk()
            ->assertJsonPath('pack.status', AGENT_PACK_STATUS_ACTIVE);
        $this->postJson('/api/v1/admin/agent-packs/support-writing/versions', [])
            ->assertCreated()
            ->assertJsonPath('pack.version', 2)
            ->assertJsonPath('pack.status', AGENT_PACK_STATUS_DRAFT);
        $this->postJson('/api/v1/admin/agent-packs/support-writing/versions/2/status', ['status' => AGENT_PACK_STATUS_ACTIVE])
            ->assertOk();
        $this->postJson('/api/v1/admin/agent-packs/support-writing/versions/1/rollback', [])
            ->assertCreated()
            ->assertJsonPath('pack.version', 3)
            ->assertJsonPath('pack.status', AGENT_PACK_STATUS_DRAFT)
            ->assertJsonPath('pack.profiles.Writer.guidance', 'Be empathetic and clear.');

        $builtin = AgentPack::query()->where('slug', 'research')->firstOrFail();
        $this->patchJson("/api/v1/admin/agent-packs/research/versions/{$builtin->version}", [
            'name' => 'Changed built-in',
        ])->assertStatus(409);
    }

    private function userWithApprovedModel(): User
    {
        $catalog = app(ProviderCatalog::class);
        $provider = array_key_first($catalog->all());
        $models = array_keys($catalog->provider($provider)['models']);
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => $provider,
            'model' => $models[0],
            'approved_models' => [
                ['provider' => $provider, 'model' => $models[0]],
                ['provider' => $provider, 'model' => $models[1]],
            ],
            'agent_models' => [],
        ]);

        return $user;
    }
}
