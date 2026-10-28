<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateTaskRequest;
use App\Http\Requests\Api\EstimateTaskRequest;
use App\Http\Requests\Api\TransitionTaskModeRequest;
use App\Services\OrchestratorService;
use App\Services\TaskModeTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(
        private readonly OrchestratorService $orchestratorService,
        private readonly TaskModeTransitionService $taskModeTransitionService,
    ) {}

    public function store(CreateTaskRequest $request): JsonResponse
    {
        $user = $request->user();
        $validatedData = $request->validated();

        return $this->orchestratorService->submit($user, $validatedData);
    }

    public function estimate(EstimateTaskRequest $request): JsonResponse
    {
        return $this->orchestratorService->estimate(
            $request->user(),
            $request->validated('prompt'),
        );
    }

    public function index(Request $request): JsonResponse
    {
        return $this->orchestratorService->index($request->user());
    }

    public function show(Request $request, int $task): JsonResponse
    {
        return $this->orchestratorService->show($request->user(), $task);
    }

    public function replay(Request $request, int $task): JsonResponse
    {
        return $this->orchestratorService->replay($request->user(), $task);
    }

    public function transitionToLive(TransitionTaskModeRequest $request, int $task): JsonResponse
    {
        return $this->taskModeTransitionService->transitionToLive($request->user(), $task);
    }
}
