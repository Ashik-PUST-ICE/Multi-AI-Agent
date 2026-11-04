<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskResponseChunk implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $taskId,
        public readonly int $userId,
        public readonly int $agentRunId,
        public readonly string $streamId,
        public readonly int $sequence,
        public readonly string $text,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'task.response.chunk';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'task_id' => $this->taskId,
            'agent_run_id' => $this->agentRunId,
            'stream_id' => $this->streamId,
            'sequence' => $this->sequence,
            'text' => $this->text,
        ];
    }
}
