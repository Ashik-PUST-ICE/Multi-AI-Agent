<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateSystemKillSwitchRequest;
use App\Services\ExecutionKillSwitchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityControlController extends Controller
{
    public function __construct(private readonly ExecutionKillSwitchService $killSwitch) {}

    public function stopTask(Request $request, int $task): JsonResponse
    {
        $ownedTask = $request->user()->tasks()->findOrFail($task);
        $this->killSwitch->stopTask($request->user(), $ownedTask, reason: 'Stopped by task owner.');

        return response()->json(['task_id' => $ownedTask->id, 'status' => TASK_STATUS_CANCELLED]);
    }

    public function stopAgentRun(Request $request, int $task, int $agentRun): JsonResponse
    {
        $ownedTask = $request->user()->tasks()->findOrFail($task);
        $ownedRun = $ownedTask->agentRuns()->findOrFail($agentRun);
        $this->killSwitch->stopTask($request->user(), $ownedTask, $ownedRun, 'Agent run stopped by task owner.');

        return response()->json([
            'task_id' => $ownedTask->id,
            'agent_run_id' => $ownedRun->id,
            'status' => AGENT_RUN_STATUS_CANCELLED,
        ]);
    }

    public function showSystemKillSwitch(): JsonResponse
    {
        return response()->json(['enabled' => $this->killSwitch->systemSwitchEnabled()]);
    }

    public function updateSystemKillSwitch(UpdateSystemKillSwitchRequest $request): JsonResponse
    {
        $this->killSwitch->setSystemSwitch(
            $request->user(),
            $request->boolean('enabled'),
            $request->validated('reason'),
        );

        return response()->json(['enabled' => $this->killSwitch->systemSwitchEnabled()]);
    }
}
