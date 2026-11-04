<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateTaskScheduleRequest;
use App\Services\TaskScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TaskScheduleController extends Controller
{
    public function __construct(private readonly TaskScheduleService $schedules) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['schedules' => $this->schedules->index($request->user())]);
    }

    public function store(CreateTaskScheduleRequest $request): JsonResponse
    {
        try {
            return response()->json([
                'schedule' => $this->schedules->create($request->user(), $request->validated()),
                'message' => 'Task schedule created. Each generated task starts in Sandbox.',
            ], 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $schedule): JsonResponse
    {
        $cancelled = $this->schedules->cancel($request->user(), $schedule);

        return response()->json([
            'message' => $cancelled ? 'Task schedule cancelled.' : 'Task schedule was already inactive.',
        ]);
    }
}
