<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\BootstrapAdminAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdminAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_active_admin_accounts_and_grants_super_admin_all_permissions(): void
    {
        config([
            'bootstrap_admins.admin' => [
                'name' => 'Administrator',
                'email' => 'admin@example.test',
                'password' => 'strong-admin-password',
            ],
            'bootstrap_admins.super_admin' => [
                'name' => 'Super Administrator',
                'email' => 'super-admin@example.test',
                'password' => 'strong-super-password',
            ],
        ]);

        $seeder = app(BootstrapAdminAccountsSeeder::class);
        $seeder->run();
        $seeder->run();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $superAdmin = User::query()->where('email', 'super-admin@example.test')->firstOrFail();

        $this->assertSame(2, User::query()->count());
        $this->assertSame(USER_STATUS_ACTIVE, $admin->status);
        $this->assertSame(USER_STATUS_ACTIVE, $superAdmin->status);
        $this->assertTrue(Hash::check('strong-admin-password', $admin->password));
        $this->assertTrue(Hash::check('strong-super-password', $superAdmin->password));
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertFalse($admin->hasRole('super-admin'));
        $this->assertTrue($superAdmin->hasRole('super-admin'));
        $this->assertTrue($superAdmin->can('platform.manage'));
        $this->assertSame(
            Permission::query()->where('guard_name', 'web')->count(),
            $superAdmin->roles()->first()->permissions()->count(),
        );
    }
}
