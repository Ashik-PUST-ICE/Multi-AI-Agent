<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateAgentSpecVersionRequest;
use App\Http\Requests\Api\TransitionAgentSpecStatusRequest;
use App\Http\Requests\Api\UpdateAgentSpecRequest;
use App\Models\User;
use App\Services\AgentSpecService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentSpecController extends Controller
{
    public function __construct(
        private readonly AgentSpecService $agentSpecService,
    ) {}

    public function index(): JsonResponse
    {
        return $this->agentSpecService->index();
    }

    public function show(string $agentName, int $version): JsonResponse
    {
        return $this->agentSpecService->show($agentName, $version);
    }

    public function store(CreateAgentSpecVersionRequest $request, string $agentName): JsonResponse
    {
        return $this->agentSpecService->createVersion(
            $request->user(),
            $agentName,
            $request->validated(),
        );
    }

    public function update(UpdateAgentSpecRequest $request, string $agentName, int $version): JsonResponse
    {
        return $this->agentSpecService->updateDraft(
            $request->user(),
            $agentName,
            $version,
            $request->validated(),
        );
    }

    public function transition(
        TransitionAgentSpecStatusRequest $request,
        string $agentName,
        int $version,
    ): JsonResponse {
        $validatedData = $request->validated();

        return $this->agentSpecService->transition(
            $agentName,
            $version,
            $validatedData['status'],
        );
    }

    public function rollback(Request $request, string $agentName, int $version): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->agentSpecService->rollback($user, $agentName, $version);
    }
}
