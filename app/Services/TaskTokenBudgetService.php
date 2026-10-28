<?php

namespace App\Services;

use App\Llm\ChatResponse;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TaskTokenBudgetService
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, int|float>  $agentUsage
     */
    public function maximumOutputTokens(
        ?AgentRun $agentRun,
        array $messages,
        int $perCallLimit,
        int $agentTokenLimit,
        array $agentUsage,
    ): int {
        $estimatedInputTokens = $this->estimateMessageTokens($messages);
        $agentRemainingTokens = $agentTokenLimit - (int) ($agentUsage['total_tokens'] ?? 0);
        $taskRemainingTokens = (int) config('llm.task_token_budget', 20000);

        if ($agentRun !== null) {
            $taskRemainingTokens -= $this->taskTokenUsage($agentRun);
        }

        $maximumOutputTokens = min(
            $perCallLimit,
            $agentRemainingTokens - $estimatedInputTokens,
            $taskRemainingTokens - $estimatedInputTokens,
        );

        if ($maximumOutputTokens < 1) {
            throw new RuntimeException('The agent or task token budget has been exhausted.');
        }

        return $maximumOutputTokens;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int}
     */
    public function responseUsage(array $messages, ChatResponse $response): array
    {
        $reportedInputTokens = $response->usage['input_tokens'] ?? null;
        $reportedOutputTokens = $response->usage['output_tokens'] ?? null;
        $reportedTotalTokens = $response->usage['total_tokens'] ?? null;
        $estimatedInputTokens = $this->estimateMessageTokens($messages);
        $estimatedOutputTokens = $this->estimateResponseTokens($response);

        if (is_numeric($reportedTotalTokens)) {
            $totalTokens = max(0, (int) $reportedTotalTokens);
            $inputTokens = is_numeric($reportedInputTokens)
                ? max(0, (int) $reportedInputTokens)
                : min($estimatedInputTokens, $totalTokens);
            $outputTokens = is_numeric($reportedOutputTokens)
                ? max(0, (int) $reportedOutputTokens)
                : max(0, $totalTokens - $inputTokens);
        } else {
            $inputTokens = is_numeric($reportedInputTokens)
                ? max(0, (int) $reportedInputTokens)
                : $estimatedInputTokens;
            $outputTokens = is_numeric($reportedOutputTokens)
                ? max(0, (int) $reportedOutputTokens)
                : $estimatedOutputTokens;
        }

        $totalTokens = $inputTokens + $outputTokens;

        if (is_numeric($reportedTotalTokens)) {
            $totalTokens = max($totalTokens, (int) $reportedTotalTokens);
        }

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
        ];
    }

    /**
     * Atomically records usage, retaining actual usage even when it crosses a hard limit.
     *
     * @param  array<string, int|float>  $cumulativeUsage
     */
    public function recordUsage(AgentRun $agentRun, array $cumulativeUsage, int $agentTokenLimit): void
    {
        $budgetError = DB::transaction(function () use ($agentRun, $cumulativeUsage, $agentTokenLimit): ?string {
            $lockedTask = $agentRun->task()->lockForUpdate()->first();

            if ($lockedTask === null) {
                throw new RuntimeException('The task for this token usage could not be found.');
            }

            $lockedAgentRun = AgentRun::query()
                ->whereKey($agentRun->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAgentRun->token_usage = $this->normalizedUsage($cumulativeUsage);
            $lockedAgentRun->save();

            $taskTokenUsage = $this->taskTokenUsage($lockedAgentRun);
            $agentTokenUsage = (int) ($lockedAgentRun->token_usage['total_tokens'] ?? 0);

            if ($agentTokenUsage > $agentTokenLimit) {
                $agentRun->setAttribute('token_usage', $lockedAgentRun->token_usage);

                return 'The agent exceeded its configured token budget.';
            }

            if ($taskTokenUsage > (int) config('llm.task_token_budget', 20000)) {
                $agentRun->setAttribute('token_usage', $lockedAgentRun->token_usage);

                return 'The task exceeded its configured total token budget.';
            }

            $agentRun->setAttribute('token_usage', $lockedAgentRun->token_usage);

            return null;
        });

        if ($budgetError !== null) {
            throw new RuntimeException($budgetError);
        }
    }

    /**
     * @param  array<string, int|float>  $usage
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int}
     */
    private function normalizedUsage(array $usage): array
    {
        $inputTokens = max(0, (int) ($usage['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($usage['output_tokens'] ?? 0));
        $totalTokens = max(
            $inputTokens + $outputTokens,
            (int) ($usage['total_tokens'] ?? 0),
        );

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
        ];
    }

    private function taskTokenUsage(AgentRun $agentRun): int
    {
        $agentRuns = AgentRun::query()
            ->where('task_id', $agentRun->task_id)
            ->get(['id', 'token_usage']);

        return (int) $agentRuns->sum(static fn (AgentRun $run): int => (int) ($run->token_usage['total_tokens'] ?? 0));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function estimateMessageTokens(array $messages): int
    {
        $messageContent = json_encode($messages, JSON_THROW_ON_ERROR);
        $charactersPerToken = max(1, (int) config('llm.estimated_characters_per_token', 3));

        return max(1, (int) ceil(mb_strlen($messageContent) / $charactersPerToken));
    }

    private function estimateResponseTokens(ChatResponse $response): int
    {
        $responseContent = json_encode([
            'text' => $response->text,
            'tool_calls' => $response->toolCalls,
        ], JSON_THROW_ON_ERROR);
        $charactersPerToken = max(1, (int) config('llm.estimated_characters_per_token', 3));

        return max(1, (int) ceil(mb_strlen($responseContent) / $charactersPerToken));
    }
}
