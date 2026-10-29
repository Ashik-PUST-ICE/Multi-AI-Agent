<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ObservabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObservabilityController extends Controller
{
    public function __construct(private readonly ObservabilityService $observabilityService) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->observabilityService->summary($request->user()));
    }

    public function trace(Request $request, int $task): JsonResponse
    {
        $ownedTask = $request->user()->tasks()
            ->with(['agentRuns', 'runEvents'])
            ->findOrFail($task);

        return response()->json($this->observabilityService->exportTaskTrace($ownedTask));
    }
}
