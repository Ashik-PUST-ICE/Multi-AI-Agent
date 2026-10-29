<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateAgentPackRequest;
use App\Http\Requests\Api\InstallAgentPackRequest;
use App\Http\Requests\Api\TransitionAgentPackStatusRequest;
use App\Http\Requests\Api\UpdateAgentPackRequest;
use App\Services\AgentPackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentPackController extends Controller
{
    public function __construct(
        private readonly AgentPackService $agentPackService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->agentPackService->index($request->user());
    }

    public function install(InstallAgentPackRequest $request, string $slug): JsonResponse
    {
        return $this->agentPackService->install(
            $request->user(),
            $slug,
            $request->validated('enabled'),
        );
    }

    public function adminIndex(): JsonResponse
    {
        return $this->agentPackService->adminIndex();
    }

    public function store(CreateAgentPackRequest $request): JsonResponse
    {
        return $this->agentPackService->create($request->user(), $request->validated());
    }

    public function createVersion(Request $request, string $slug): JsonResponse
    {
        return $this->agentPackService->createVersion($request->user(), $slug, []);
    }

    public function update(UpdateAgentPackRequest $request, string $slug, int $version): JsonResponse
    {
        return $this->agentPackService->updateDraft($slug, $version, $request->validated());
    }

    public function transition(TransitionAgentPackStatusRequest $request, string $slug, int $version): JsonResponse
    {
        return $this->agentPackService->transition($slug, $version, $request->validated('status'));
    }

    public function rollback(Request $request, string $slug, int $version): JsonResponse
    {
        return $this->agentPackService->rollback($request->user(), $slug, $version);
    }
}
