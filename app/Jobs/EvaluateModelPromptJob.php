<?php

namespace App\Jobs;

use App\Models\EvaluationResult;
use App\Services\AgentActivityLogger;
use App\Services\EvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EvaluateModelPromptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $evaluationResultId)
    {
        $this->timeout = (int) config('llm.timeout', 30) + 10;
    }

    public function handle(EvaluationService $evaluationService): void
    {
        $evaluationService->executeResult($this->evaluationResultId);
    }

    public function failed(?Throwable $exception): void
    {
        $result = EvaluationResult::withoutGlobalScopes()->find($this->evaluationResultId);
        if ($result === null || ! in_array($result->status, ['pending', 'running'], true)) {
            return;
        }

        $message = $exception?->getMessage() ?? 'Evaluation worker failed.';
        $safeMessage = app(AgentActivityLogger::class)->redactValue(['message' => $message])['message'];
        $result->fill([
            'status' => 'failed',
            'error_message' => is_string($safeMessage) ? $safeMessage : 'Evaluation worker failed.',
        ])->save();
    }
}
