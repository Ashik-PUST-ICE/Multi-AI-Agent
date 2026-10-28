<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_private_workspace_and_assigns_only_the_user_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Workspace Owner',
            'email' => 'owner@example.test',
            'password' => 'secure-pass-12345',
            'password_confirmation' => 'secure-pass-12345',
        ])->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'uuid', 'name', 'email', 'roles'],
            ])
            ->assertJsonPath('user.roles', ['user']);

        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertNotEmpty($response->json('token'));
        $this->assertNotNull($user->tenant_id);
        $this->assertSame("{$user->name}'s Workspace", $user->tenant->name);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('agent_trust', 4);
        $this->assertDatabaseHas('agent_trust', [
            'user_id' => $user->id,
            'agent_name' => 'Research',
            'trust_level' => AGENT_TRUST_LEVEL_READ,
        ]);
        $this->assertTrue($user->hasRole('user'));
        $this->assertFalse($user->hasRole('admin'));

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'owner@example.test');
    }

    public function test_login_issues_a_token_only_for_valid_active_user_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'active@example.test',
            'password' => 'secure-pass-12345',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'active@example.test',
            'password' => 'secure-pass-12345',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'active@example.test',
            'password' => 'wrong-password-123',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'The provided credentials are invalid.');

        $user->forceFill(['status' => USER_STATUS_INACTIVE])->save();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'active@example.test',
            'password' => 'secure-pass-12345',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'The provided credentials are invalid.');
    }

    public function test_logout_revokes_the_current_personal_access_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        Auth::forgetGuards();
        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/user')
            ->assertUnauthorized();
    }

    public function test_tenant_scope_filters_workspace_records_and_owner_routes_keep_tasks_private(): void
    {
        $owner = User::factory()->create();
        $otherWorkspaceUser = User::factory()->create();
        $ownerTask = $owner->tasks()->create(['title' => 'Owner private task']);
        $otherTask = $otherWorkspaceUser->tasks()->create(['title' => 'Other workspace task']);
        $tenantContext = app(TenantContext::class);

        $tenantContext->initialize((int) $owner->tenant_id);
        $this->assertSame([$ownerTask->id], Task::query()->pluck('id')->all());
        $tenantContext->clear();

        $tenantContext->initialize((int) $otherWorkspaceUser->tenant_id);
        $this->assertSame([$otherTask->id], Task::query()->pluck('id')->all());
        $tenantContext->clear();

        Sanctum::actingAs($otherWorkspaceUser);
        $this->getJson("/api/v1/tasks/{$ownerTask->id}")->assertNotFound();
    }

    public function test_admin_role_does_not_bypass_task_ownership_policies(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $adminRole = Role::query()->create([
            'name' => 'super-admin',
            'guard_name' => 'web',
            'display_name' => 'Super Admin',
            'status' => STATUS_ACTIVE,
            'user_type' => USER_ROLE_SUPER_ADMIN,
        ]);
        $admin->assignRole($adminRole);
        $task = $owner->tasks()->create(['title' => 'Private owner task']);

        $this->assertFalse(Gate::forUser($admin)->allows('view', $task));
        $this->assertTrue(Gate::forUser($owner)->allows('view', $task));
    }

    public function test_registration_validation_rejects_weak_and_duplicate_accounts(): void
    {
        $registration = [
            'name' => 'New User',
            'email' => 'new-user@example.test',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ];
        $this->postJson('/api/v1/auth/register', $registration)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $registration['password'] = 'secure-pass-12345';
        $registration['password_confirmation'] = 'secure-pass-12345';
        $this->postJson('/api/v1/auth/register', $registration)->assertCreated();
        $this->postJson('/api/v1/auth/register', $registration)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
