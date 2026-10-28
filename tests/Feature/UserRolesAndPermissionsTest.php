<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserRolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_receive_a_uuid_and_sensitive_authentication_fields_are_protected(): void
    {
        $user = User::factory()->create([
            'google2fa_secret' => 'private-test-secret',
        ]);
        $user->refresh();

        $this->assertNotEmpty($user->uuid);
        $this->assertSame(USER_STATUS_ACTIVE, $user->status);
        $this->assertSame(STATUS_PENDING, $user->email_verification_status);
        $this->assertSame('private-test-secret', $user->google2fa_secret);
        $this->assertNotSame(
            'private-test-secret',
            DB::table('users')->where('id', $user->id)->value('google2fa_secret'),
        );
        $this->assertArrayNotHasKey('google2fa_secret', $user->toArray());
        $this->assertTrue(Schema::hasColumns('users', [
            'uuid',
            'nick_name',
            'mobile',
            'country',
            'image',
            'email_verification_status',
            'phone_verification_status',
            'google_auth_status',
            'google2fa_secret',
            'google_id',
            'facebook_id',
            'verify_token_hash',
            'otp_hash',
            'otp_expiry',
            'created_by',
            'tenant_id',
            'status',
            'last_seen',
            'deleted_at',
        ]));
        $this->assertFalse(Schema::hasColumn('users', 'role'));
    }

    public function test_spatie_roles_and_permissions_can_be_assigned_to_users(): void
    {
        $permission = Permission::create([
            'name' => 'tasks.view',
            'guard_name' => 'web',
            'display_name' => 'View tasks',
            'module' => 1,
        ]);
        $role = Role::create([
            'name' => 'researcher',
            'guard_name' => 'web',
            'display_name' => 'Researcher',
        ]);
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue($user->hasRole('researcher'));
        $this->assertTrue($user->can('tasks.view'));
    }
}
