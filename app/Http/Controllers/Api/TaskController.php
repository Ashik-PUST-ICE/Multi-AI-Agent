<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateTaskRequest;
use App\Services\OrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(
        private readonly OrchestratorService $orchestratorService,
    ) {}

    public function store(CreateTaskRequest $request): JsonResponse
    {
        $user = $request->user();
        $validatedData = $request->validated();

        return $this->orchestratorService->submit($user, $validatedData);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->orchestratorService->index($request->user());
    }

    public function show(Request $request, int $task): JsonResponse
    {
        return $this->orchestratorService->show($request->user(), $task);
    }
}
