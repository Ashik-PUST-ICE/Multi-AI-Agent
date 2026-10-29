<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateTaskReplayShareRequest;
use App\Services\TaskReplayShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskReplayShareController extends Controller
{
    public function __construct(
        private readonly TaskReplayShareService $taskReplayShareService,
    ) {}

    public function store(CreateTaskReplayShareRequest $request, int $task): JsonResponse
    {
        $validatedData = $request->validated();

        return response()->json([
            'share' => $this->taskReplayShareService->create(
                $request->user(),
                $task,
                (int) ($validatedData['expires_in_hours'] ?? 24),
            ),
        ], 201, ['Cache-Control' => 'no-store, private']);
    }

    public function destroy(Request $request, int $task, int $share): JsonResponse
    {
        $this->taskReplayShareService->revoke($request->user(), $task, $share);

        return response()->json(['message' => 'Replay share revoked.']);
    }

    public function show(string $token): JsonResponse
    {
        return response()->json(
            $this->taskReplayShareService->sharedReplay($token),
            200,
            [
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
            ],
        );
    }
}
