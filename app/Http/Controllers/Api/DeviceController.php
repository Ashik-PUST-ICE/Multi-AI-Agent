<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateDeviceRequest;
use App\Models\User;
use App\Services\ExecutionKillSwitchService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    use ResponseTrait;

    public function index(Request $request): JsonResponse
    {
        return $this->success([
            'devices' => $request->user()->tokens()
                ->latest('created_at')
                ->get(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at'])
                ->map(fn ($token): array => $this->deviceData($token))
                ->values(),
        ]);
    }

    public function store(CreateDeviceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $expiresAt = isset($validated['expires_at'])
            ? Carbon::parse($validated['expires_at'])
            : now()->addDays(90);

        if ($expiresAt->greaterThan(now()->addDays(365))) {
            return $this->error('Device tokens may not expire more than 365 days from now.', 422);
        }

        /** @var User $user */
        $user = $request->user();
        $token = $user->createToken($validated['name'], ['*'], $expiresAt);

        return $this->success([
            'device' => $this->deviceData($token->accessToken),
            'token' => $token->plainTextToken,
        ], CREATED_SUCCESSFULLY, 201);
    }

    public function destroy(Request $request, int $device, ExecutionKillSwitchService $killSwitch): JsonResponse
    {
        $user = $request->user();
        DB::transaction(function () use ($device, $killSwitch, $user): void {
            $token = $user->tokens()->whereKey($device)->lockForUpdate()->firstOrFail();
            $entries = $user->deviceTaskQueueEntries()
                ->with('task')
                ->where('device_token_id', $device)
                ->whereIn('status', ['queued', 'needs_confirmation'])
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                $entry->update(['status' => 'cancelled']);
                if ($entry->task !== null
                    && in_array($entry->task->status, [TASK_STATUS_PENDING, TASK_STATUS_RUNNING], true)) {
                    $killSwitch->stopTask($user, $entry->task, reason: 'Paired device token was revoked.');
                }
            }

            $token->delete();
        });

        return $this->success([], 'Device token revoked.');
    }

    /**
     * @return array{id: int, name: string, abilities: array, last_used_at: ?string, expires_at: ?string, created_at: ?string}
     */
    private function deviceData(object $token): array
    {
        return [
            'id' => (int) $token->id,
            'name' => (string) $token->name,
            'abilities' => is_array($token->abilities) ? $token->abilities : [],
            'last_used_at' => $token->last_used_at?->toISOString(),
            'expires_at' => $token->expires_at?->toISOString(),
            'created_at' => $token->created_at?->toISOString(),
        ];
    }
}
