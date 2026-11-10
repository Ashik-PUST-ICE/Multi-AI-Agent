<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceLoginRequest;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceAuthorizationController extends Controller
{
    use ResponseTrait;

    private const USER_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function issueCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_name' => ['required', 'string', 'min:1', 'max:100'],
        ]);
        if (trim($validated['device_name']) === '') {
            return $this->error('Device name cannot be blank.', 422);
        }
        DeviceLoginRequest::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();
        do {
            $userCode = $this->newUserCode();
        } while (DeviceLoginRequest::query()->where('user_code_hash', hash('sha256', $userCode))->exists());
        $deviceCode = Str::random(64);
        $requestRecord = DeviceLoginRequest::query()->create([
            'device_code_hash' => hash('sha256', $deviceCode),
            'user_code_hash' => hash('sha256', $userCode),
            'device_name' => trim($validated['device_name']),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        return $this->success([
            'device_code' => $deviceCode,
            'user_code' => $this->formatUserCode($userCode),
            'verification_uri' => url('/device/authorize'),
            'expires_in' => max(0, now()->diffInSeconds($requestRecord->expires_at)),
            'interval' => 5,
        ], null, 201);
    }

    public function poll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_code' => ['required', 'string', 'size:64', 'alpha_num'],
        ]);
        $hash = hash('sha256', $validated['device_code']);

        return DB::transaction(function () use ($hash): JsonResponse {
            $deviceRequest = DeviceLoginRequest::query()
                ->where('device_code_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($deviceRequest === null) {
                return $this->error('The device authorization request is invalid or expired.', 400);
            }
            if ($deviceRequest->expires_at->isPast()) {
                if ($deviceRequest->status === 'pending') {
                    $deviceRequest->update(['status' => 'expired']);
                }

                return $this->error('The device authorization request has expired.', 400);
            }
            if ($deviceRequest->status === 'pending') {
                return $this->error('Authorization is still pending in the browser.', 428, [
                    'error' => 'authorization_pending',
                ]);
            }
            if ($deviceRequest->status !== 'approved' || $deviceRequest->user_id === null || $deviceRequest->consumed_at !== null) {
                return $this->error('The device authorization request is no longer available.', 400);
            }

            $user = User::query()->whereKey($deviceRequest->user_id)->first();
            if ($user === null || $user->status !== USER_STATUS_ACTIVE) {
                $deviceRequest->update(['status' => 'denied', 'consumed_at' => now()]);

                return $this->error('The account is no longer available for device authorization.', 403);
            }

            $token = $user->createToken($deviceRequest->device_name, ['*'], now()->addDays(90));
            $deviceRequest->update([
                'status' => 'consumed',
                'consumed_at' => now(),
            ]);

            return $this->success([
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toISOString(),
            ]);
        });
    }

    public function showAuthorizationForm(Request $request)
    {
        return response()->view('device-authorize', [
            'userCode' => '',
        ]);
    }

    public function authorizeDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_code' => ['required', 'string', 'max:16'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'confirm' => ['accepted'],
        ]);
        $userCode = $this->normalizeUserCode($validated['user_code']);
        $user = User::query()
            ->where('email', mb_strtolower($validated['email']))
            ->first();
        if ($user === null || $user->status !== USER_STATUS_ACTIVE || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The credentials or device code are invalid.'],
            ]);
        }

        $authorized = DB::transaction(function () use ($userCode, $user): bool {
            $deviceRequest = DeviceLoginRequest::query()
                ->where('user_code_hash', hash('sha256', $userCode))
                ->lockForUpdate()
                ->first();
            if (
                $deviceRequest === null
                || $deviceRequest->status !== 'pending'
                || $deviceRequest->expires_at->isPast()
            ) {
                return false;
            }
            $deviceRequest->update([
                'status' => 'approved',
                'user_id' => $user->id,
                'approved_at' => now(),
            ]);

            return true;
        });

        if (! $authorized) {
            return $this->error('This device code expired or was already used. Start a new login from your CLI.', 422);
        }

        return $this->success([], 'Device authorized. Return to your CLI to finish signing in.');
    }

    public function denyDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_code' => ['required', 'string', 'max:16'],
        ]);
        $userCode = $this->normalizeUserCode($validated['user_code']);
        $denied = DeviceLoginRequest::query()
            ->where('user_code_hash', hash('sha256', $userCode))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->update(['status' => 'denied']);

        return $denied === 1
            ? $this->success([], 'Device authorization denied.')
            : $this->error('This device code expired or was already used.', 422);
    }

    private function newUserCode(): string
    {
        $code = '';
        for ($index = 0; $index < 8; $index++) {
            $code .= self::USER_CODE_ALPHABET[random_int(0, strlen(self::USER_CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    private function normalizeUserCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($code)) ?? '');
    }

    private function formatUserCode(string $code): string
    {
        $normalized = $this->normalizeUserCode($code);

        return strlen($normalized) === 8
            ? substr($normalized, 0, 4).'-'.substr($normalized, 4)
            : $normalized;
    }
}
