<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'user', 'guard_name' => 'web'],
            [
                'display_name' => 'User',
                'status' => STATUS_ACTIVE,
                'user_type' => USER_ROLE_USER,
            ],
        );
        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            [
                'display_name' => 'Admin',
                'status' => STATUS_ACTIVE,
                'user_type' => USER_ROLE_ADMIN,
            ],
        );
        $agentSpecsPermission = Permission::query()->firstOrCreate(
            ['name' => 'agent_specs.manage', 'guard_name' => 'web'],
            ['display_name' => 'Manage agent specifications', 'module' => 1],
        );
        $adminRole->givePermissionTo($agentSpecsPermission);
    }
}
