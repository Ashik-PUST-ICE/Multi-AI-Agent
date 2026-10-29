<?php

namespace App\Services;

use App\Models\RunEvent;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;

class ObservabilityService
{
    /**
     * @return array{resourceSpans: list<array<string, mixed>>}
     */
    public function exportTaskTrace(Task $task): array
    {
        $spans = [
            $this->span(
                (string) $task->trace_id,
                (string) $task->trace_span_id,
                null,
                'task.run',
                $task->started_at ?? $task->created_at,
                $task->completed_at ?? $task->started_at ?? $task->created_at,
                $this->attributes([
                    'task.status' => (int) $task->status,
                    'task.mode' => (string) $task->mode,
                ]),
                $task->status === TASK_STATUS_FAILED,
            ),
        ];

        foreach ($task->agentRuns as $agentRun) {
            $start = $agentRun->started_at ?? $agentRun->created_at;
            $end = $agentRun->completed_at ?? $start;
            $spans[] = $this->span(
                (string) $agentRun->trace_id,
                (string) $agentRun->trace_span_id,
                $agentRun->parent_span_id,
                'agent.run',
                $start,
                $end,
                $this->attributes([
                    'agent.name' => (string) $agentRun->agent_name,
                    'agent.status' => (int) $agentRun->status,
                    'agent.mode' => (string) $agentRun->mode,
                ]),
                $agentRun->status === AGENT_RUN_STATUS_FAILED,
            );
        }

        foreach ($task->runEvents as $event) {
            $payload = $event->payload ?? [];
            $durationMs = is_numeric($payload['duration_ms'] ?? null)
                ? max(0, (int) $payload['duration_ms'])
                : 0;
            $end = $event->occurred_at;
            $start = $durationMs > 0 ? $end->copy()->subMilliseconds($durationMs) : $end;
            $attributes = [
                'event.type' => (string) $event->event_type,
                'event.sequence' => (int) $event->sequence,
                'event.mode' => (string) $event->mode,
            ];
            foreach (['tool_name', 'provider', 'model'] as $field) {
                if (is_string($payload[$field] ?? null)) {
                    $attributes['event.'.str_replace('_', '.', $field)] = mb_substr($payload[$field], 0, 200);
                }
            }
            foreach (['duration_ms', 'call_count'] as $field) {
                if (is_numeric($payload[$field] ?? null)) {
                    $attributes['event.'.str_replace('_', '.', $field)] = (int) $payload[$field];
                }
            }
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
                if (is_numeric($payload['usage'][$field] ?? null)) {
                    $attributes['llm.'.$field] = (int) $payload['usage'][$field];
                }
            }
            if (is_array($payload['cost'] ?? null)
                && is_numeric($payload['cost']['amount'] ?? null)
                && is_string($payload['cost']['currency'] ?? null)) {
                $attributes['llm.cost.amount'] = (float) $payload['cost']['amount'];
                $attributes['llm.cost.currency'] = mb_substr($payload['cost']['currency'], 0, 10);
            }
            if (in_array($event->event_type, ['agent.failed', 'tool.failed', 'task.failed', 'observability.silent_failure', 'observability.stagnation_detected'], true)) {
                $attributes['failure.category'] = $this->failureCategory($event->event_type, $payload);
            }

            $spans[] = $this->span(
                (string) $event->trace_id,
                (string) $event->trace_span_id,
                $event->parent_span_id,
                (string) $event->event_type,
                $start,
                $end,
                $this->attributes($attributes),
                str_ends_with($event->event_type, '.failed')
                    || in_array($event->event_type, ['observability.silent_failure', 'observability.stagnation_detected'], true),
            );
        }

        return [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => $this->attributes([
                        'service.name' => (string) config('app.name'),
                    ]),
                ],
                'scopeSpans' => [[
                    'scope' => [
                        'name' => 'multi-ai-agent.flight-recorder',
                        'version' => '1.0.0',
                    ],
                    'spans' => $spans,
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $since = now()->subDays(30);
        $events = RunEvent::query()
            ->whereHas('task', fn ($query) => $query->where('user_id', $user->id))
            ->where('occurred_at', '>=', $since)
            ->whereIn('event_type', [
                'agent.failed',
                'tool.failed',
                'observability.silent_failure',
                'observability.stagnation_detected',
            ])
            ->latest('id')
            ->limit(1000)
            ->get(['event_type', 'payload']);

        $failureCategories = [];
        $warnings = ['silent_failures' => 0, 'stagnation' => 0];
        foreach ($events as $event) {
            if ($event->event_type === 'observability.silent_failure') {
                $warnings['silent_failures']++;
            } elseif ($event->event_type === 'observability.stagnation_detected') {
                $warnings['stagnation']++;
            }
            $category = $this->failureCategory($event->event_type, $event->payload ?? []);
            $failureCategories[$category] = ($failureCategories[$category] ?? 0) + 1;
        }

        $assessments = RunEvent::query()
            ->whereHas('task', fn ($query) => $query->where('user_id', $user->id))
            ->where('event_type', 'skeptic.assessment')
            ->where('occurred_at', '>=', $since)
            ->latest('id')
            ->limit(1000)
            ->get(['payload']);
        $noFindingApprovals = $assessments->filter(static function (RunEvent $event): bool {
            $payload = $event->payload ?? [];

            return ($payload['approved'] ?? false) === true
                && ($payload['findings'] ?? []) === [];
        })->count();
        $reviewSignal = [
            'sample_size' => $assessments->count(),
            'finding_free_approval_rate' => $assessments->isEmpty()
                ? null
                : round($noFindingApprovals / $assessments->count(), 2),
            'warning' => $assessments->count() >= 5
                && $noFindingApprovals / $assessments->count() >= 0.9,
            'interpretation' => 'Heuristic review-pattern signal only; it is not proof of sycophancy.',
        ];

        return [
            'period_days' => 30,
            'failure_categories' => $failureCategories,
            'warnings' => $warnings,
            'skeptic_review_signal' => $reviewSignal,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function failureCategory(string $eventType, array $payload): string
    {
        $description = strtolower(implode(' ', array_filter([
            $eventType,
            is_string($payload['failure_category'] ?? null) ? $payload['failure_category'] : null,
            is_string($payload['error'] ?? null) ? $payload['error'] : null,
        ])));

        return match (true) {
            str_contains($description, 'budget'), str_contains($description, 'cost'), str_contains($description, 'token limit') => 'cost',
            str_contains($description, 'specification'), str_contains($description, 'schema'), str_contains($description, 'validation') => 'validation',
            str_contains($description, 'trust'), str_contains($description, 'kill switch'), str_contains($description, 'security'), str_contains($description, 'approval') => 'security',
            str_contains($description, 'provider'), str_contains($description, 'connection'), str_contains($description, 'model request') => 'provider',
            str_contains($description, 'tool.') => 'tool',
            str_contains($description, 'handoff'), str_contains($description, 'envelope'), str_contains($description, 'orchestrat') => 'coordination',
            default => 'other',
        };
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $values
     * @return list<array{key: string, value: array<string, string|int|float|bool>}>
     */
    private function attributes(array $values): array
    {
        $attributes = [];
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            $valueKey = match (true) {
                is_bool($value) => 'boolValue',
                is_int($value) => 'intValue',
                is_float($value) => 'doubleValue',
                default => 'stringValue',
            };
            if (is_int($value)) {
                $value = (string) $value;
            }
            $attributes[] = ['key' => $key, 'value' => [$valueKey => $value]];
        }

        return $attributes;
    }

    /**
     * @param  list<array{key: string, value: array<string, string|int|float|bool>}>  $attributes
     * @return array<string, mixed>
     */
    private function span(
        string $traceId,
        string $spanId,
        ?string $parentSpanId,
        string $name,
        CarbonInterface $start,
        CarbonInterface $end,
        array $attributes,
        bool $failed,
    ): array {
        $span = [
            'traceId' => $traceId,
            'spanId' => $spanId,
            'name' => $name,
            'kind' => 1,
            'startTimeUnixNano' => $this->unixNanos($start),
            'endTimeUnixNano' => $this->unixNanos($end),
            'attributes' => $attributes,
            'status' => [
                'code' => $failed ? 2 : 1,
            ],
        ];
        if ($parentSpanId !== null) {
            $span['parentSpanId'] = $parentSpanId;
        }

        return $span;
    }

    private function unixNanos(CarbonInterface $date): string
    {
        return (string) ($date->getTimestamp() * 1_000_000_000 + ((int) $date->format('u') * 1_000));
    }
}
