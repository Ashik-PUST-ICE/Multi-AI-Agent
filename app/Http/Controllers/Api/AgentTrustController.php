<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAgentTrustRequest;
use App\Services\TrustLevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentTrustController extends Controller
{
    public function __construct(
        private readonly TrustLevelService $trustLevelService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->trustLevelService->overview($request->user());
    }

    public function update(UpdateAgentTrustRequest $request, string $agentName): JsonResponse
    {
        $user = $request->user();
        $validatedData = $request->validated();

        return $this->trustLevelService->updateLevel(
            $user,
            $agentName,
            $validatedData['trust_level'],
            $validatedData['reason'],
        );
    }
}
