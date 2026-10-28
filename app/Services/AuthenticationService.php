<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationService
{
    use ResponseTrait;

    public function __construct(
        private readonly TrustLevelService $trustLevelService,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function register(array $data): JsonResponse
    {
        /** @var array{token: string, user: User} $registration */
        $registration = DB::transaction(function () use ($data): array {
            $tenant = Tenant::query()->create([
                'name' => "{$data['name']}'s Workspace",
            ]);
            $user = new User([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
            ]);
            $user->forceFill(['tenant_id' => $tenant->id]);
            $user->save();

            $userRole = Role::query()->firstOrCreate(
                ['name' => 'user', 'guard_name' => 'web'],
                [
                    'display_name' => 'User',
                    'status' => STATUS_ACTIVE,
                    'user_type' => USER_ROLE_USER,
                ],
            );
            $user->assignRole($userRole);
            $this->trustLevelService->initializeDefaults($user);

            return [
                'token' => $user->createToken('multi-ai-agent-web')->plainTextToken,
                'user' => $user,
            ];
        });

        return $this->success([
            'token' => $registration['token'],
            'user' => $this->userData($registration['user']),
        ], CREATED_SUCCESSFULLY, 201);
    }

    /**
     * @param  array{email: string, password: string}  $credentials
     */
    public function login(array $credentials): JsonResponse
    {
        $user = User::query()
            ->where('email', mb_strtolower($credentials['email']))
            ->first();

        if ($user === null
            || $user->status !== USER_STATUS_ACTIVE
            || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are invalid.'],
            ]);
        }

        $user->forceFill(['last_seen' => now()])->save();

        return $this->success([
            'token' => $user->createToken('multi-ai-agent-web')->plainTextToken,
            'user' => $this->userData($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $currentToken = $request->user()?->currentAccessToken();

        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
        }

        return $this->success([], 'Logged out successfully.');
    }

    /**
     * @return array{id: int, uuid: string, name: string, email: string, roles: list<string>}
     */
    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
        ];
    }
}
