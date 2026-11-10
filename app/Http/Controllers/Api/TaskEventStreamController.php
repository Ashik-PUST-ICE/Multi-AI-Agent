<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RunEvent;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskEventStreamController extends Controller
{
    public function stream(Request $request, int $task): StreamedResponse
    {
        $ownerTask = $request->user()->tasks()->whereKey($task)->firstOrFail();
        $lastEventId = $request->header('Last-Event-ID', $request->query('after', '0'));
        $validated = Validator::make(['after' => $lastEventId], [
            'after' => ['required', 'integer', 'min:0'],
        ])->validate();
        $cursor = (int) $validated['after'];
        $taskId = (int) $ownerTask->id;

        return response()->stream(function () use ($taskId, $cursor): void {
            $deadline = microtime(true) + 20;
            $lastHeartbeat = microtime(true);

            echo "retry: 3000\n\n";
            flush();

            do {
                $events = RunEvent::query()
                    ->where('task_id', $taskId)
                    ->where('id', '>', $cursor)
                    ->orderBy('id')
                    ->limit(100)
                    ->get(['id', 'agent_run_id', 'event_type', 'sequence', 'status', 'occurred_at', 'parent_run_id', 'handoff_to']);

                foreach ($events as $event) {
                    $cursor = (int) $event->id;
                    $data = [
                        'task_id' => $taskId,
                        'agent_run_id' => $event->agent_run_id,
                        'event_type' => $event->event_type,
                        'sequence' => $event->sequence,
                        'status' => $event->status,
                        'occurred_at' => $event->occurred_at?->toISOString(),
                        'parent_run_id' => $event->parent_run_id,
                        'handoff_to' => $event->handoff_to,
                    ];
                    echo "id: {$cursor}\nevent: task.progress\ndata: ".json_encode($data, JSON_THROW_ON_ERROR)."\n\n";
                }

                if ($events->isNotEmpty()) {
                    flush();
                }

                $taskRecord = Task::query()->find($taskId);
                if ($taskRecord === null || $taskRecord->completed_at !== null) {
                    break;
                }

                if (microtime(true) - $lastHeartbeat >= 10) {
                    echo ": keep-alive\n\n";
                    flush();
                    $lastHeartbeat = microtime(true);
                }

                if (microtime(true) < $deadline) {
                    usleep(250_000);
                }
            } while (! connection_aborted() && microtime(true) < $deadline);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
