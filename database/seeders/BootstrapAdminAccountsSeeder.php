<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class BootstrapAdminAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = $this->accountConfig('admin');
        $superAdmin = $this->accountConfig('super_admin');

        if (mb_strtolower($admin['email']) === mb_strtolower($superAdmin['email'])) {
            throw ValidationException::withMessages([
                'email' => 'Admin and super-admin accounts must use different email addresses.',
            ]);
        }

        $this->validateAccount($admin, 'admin');
        $this->validateAccount($superAdmin, 'super_admin');

        $this->call(DatabaseSeeder::class);

        DB::transaction(function () use ($admin, $superAdmin): void {
            $adminRole = Role::query()->firstOrCreate(
                ['name' => 'admin', 'guard_name' => 'web', 'tenant_id' => null],
                [
                    'display_name' => 'Admin',
                    'status' => STATUS_ACTIVE,
                    'user_type' => USER_ROLE_ADMIN,
                ],
            );
            $superAdminRole = Role::query()->firstOrCreate(
                ['name' => 'super-admin', 'guard_name' => 'web', 'tenant_id' => null],
                [
                    'display_name' => 'Super Admin',
                    'status' => STATUS_ACTIVE,
                    'user_type' => USER_ROLE_SUPER_ADMIN,
                ],
            );
            $superAdminRole->syncPermissions(
                Permission::query()->where('guard_name', 'web')->get(),
            );

            $this->upsertAccount($admin, $adminRole);
            $this->upsertAccount($superAdmin, $superAdminRole);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Bootstrap admin accounts were created or updated.');
        $this->command?->line('Admin email: '.$admin['email']);
        $this->command?->line('Super-admin email: '.$superAdmin['email']);
        $this->command?->warn('Passwords were not displayed. Keep them private in the local environment.');
    }

    /**
     * @return array{name: string, email: string, password: string}
     */
    private function accountConfig(string $type): array
    {
        $config = config("bootstrap_admins.{$type}", []);
        $email = $config['email'] ?? null;
        $password = $config['password'] ?? null;
        $name = $config['name'] ?? null;

        return [
            'name' => is_string($name) && trim($name) !== ''
                ? trim($name)
                : ($type === 'admin' ? 'Administrator' : 'Super Administrator'),
            'email' => is_string($email) ? mb_strtolower(trim($email)) : '',
            'password' => is_string($password) ? $password : '',
        ];
    }

    /**
     * @param  array{name: string, email: string, password: string}  $account
     */
    private function validateAccount(array $account, string $key): void
    {
        $validator = Validator::make($account, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                $key => 'Configure '.strtoupper($key).'_EMAIL and '.strtoupper($key).'_PASSWORD in the local environment; use a valid email and a password of at least 12 characters.',
            ]);
        }
    }

    /**
     * @param  array{name: string, email: string, password: string}  $account
     */
    private function upsertAccount(array $account, Role $role): void
    {
        $user = User::withTrashed()->firstOrNew(['email' => $account['email']]);
        $user->forceFill([
            'name' => $account['name'],
            'email' => $account['email'],
            'password' => $account['password'],
            'status' => USER_STATUS_ACTIVE,
            'email_verified_at' => now(),
            'deleted_at' => null,
        ]);
        $user->save();
        $user->syncRoles([$role]);
    }
}
