<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserApiIntegration;
use App\Services\AuditLogService;
use App\Services\UserApiIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class ApiIntegrationController extends Controller
{
    public function __construct(
        private readonly UserApiIntegrationService $integrationService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'integrations' => UserApiIntegration::query()
                ->where('user_id', $request->user()->id)
                ->orderBy('name')
                ->get()
                ->map(fn (UserApiIntegration $integration): array => $this->integrationService->present($integration))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (UserApiIntegration::query()->where('user_id', $request->user()->id)->count() >= 20) {
            throw ValidationException::withMessages(['name' => 'A maximum of 20 REST integrations can be configured.']);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'allowed_methods' => ['required', 'array', 'min:1', 'max:2'],
            'allowed_methods.*' => ['required', 'string', 'in:GET,POST', 'distinct'],
        ]);
        if (UserApiIntegration::query()
            ->where('user_id', $request->user()->id)
            ->where('name', $data['name'])
            ->exists()) {
            throw ValidationException::withMessages(['name' => 'An integration with this name already exists.']);
        }

        try {
            $integration = $this->integrationService->create(
                $request->user(),
                $data['name'],
                $data['endpoint'],
                $data['access_token'] ?? null,
                $data['allowed_methods'],
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages(['endpoint' => $exception->getMessage()]);
        }
        $user = $request->user();
        $integrationModel = UserApiIntegration::query()
            ->where('user_id', $user->id)
            ->where('name', $data['name'])
            ->firstOrFail();
        $this->auditLogService->record($user, 'rest.integration.configured', $integrationModel, [
            'allowed_methods' => $data['allowed_methods'],
        ], $user);

        return response()->json(['integration' => $integration], 201);
    }

    public function setEnabled(Request $request, int $integration): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $user = $request->user();
        $integrationModel = UserApiIntegration::query()
            ->where('user_id', $user->id)
            ->whereKey($integration)
            ->firstOrFail();
        $saved = $this->integrationService->setEnabled($request->user(), $integration, $data['enabled']);
        $this->auditLogService->record(
            $user,
            $data['enabled'] ? 'rest.integration.enabled' : 'rest.integration.disabled',
            $integrationModel,
            ['enabled' => $data['enabled']],
            $user,
        );

        return response()->json(['integration' => $saved]);
    }

    public function destroy(Request $request, int $integration): JsonResponse
    {
        $user = $request->user();
        $integrationModel = UserApiIntegration::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($integration)
            ->firstOrFail();
        $integrationModel->delete();
        $this->auditLogService->record($user, 'rest.integration.deleted', $integrationModel, [], $user);

        return response()->json(['deleted' => true]);
    }
}
