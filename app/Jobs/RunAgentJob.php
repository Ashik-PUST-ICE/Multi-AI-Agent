<?php

namespace App\Jobs;

use App\Services\AgentRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunAgentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly int $agentRunId,
        ?int $timeoutSeconds = null,
    ) {
        $this->timeout = max(1, $timeoutSeconds ?? (int) config('llm.agent_timeout', 60));
    }

    public function handle(AgentRunService $agentRunService): void
    {
        $agentRunService->run($this->agentRunId);
    }

    public function failed(Throwable $exception): void
    {
        app(AgentRunService::class)->fail($this->agentRunId, $exception);
    }
}
