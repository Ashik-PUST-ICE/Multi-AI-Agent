<?php

namespace App\Services;

use App\Jobs\EvaluateModelPromptJob;
use App\Llm\LlmProvider;
use App\Llm\UserLlmProviderResolver;
use App\Models\AgentSpec;
use App\Models\EvaluationCase;
use App\Models\EvaluationResult;
use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class EvaluationService
{
    public function __construct(
        private readonly UserLlmProviderResolver $providerResolver,
        private readonly EvaluationScorer $scorer,
        private readonly AgentActivityLogger $activityLogger,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array{name: string, agent_name: string, prompt: string, reference_answer?: string|null, criteria?: list<string>}  $data
     */
    public function createCase(User $user, array $data): EvaluationCase
    {
        $case = EvaluationCase::query()->create([
            ...$data,
            'user_id' => $user->id,
        ]);
        $this->auditLogService->record($user, 'evaluation.case.created', $case, [
            'agent_name' => $case->agent_name,
        ], $user);

        return $case;
    }

    /**
     * @return array{cases: Collection, approved_models: list<array{provider: string, model: string, credentials_configured: bool}>, specifications: Collection, results: Collection}
     */
    public function data(User $user): array
    {
        $approvedModels = $user->llmSettings?->approved_models ?? [];
        $models = array_map(fn (array $model): array => [
            'provider' => $model['provider'],
            'model' => $model['model'],
            'credentials_configured' => $this->providerResolver->hasConfiguredCredentials($user, $model['provider']),
        ], $approvedModels);

        return [
            'cases' => EvaluationCase::query()->latest('id')->limit(100)->get(),
            'approved_models' => $models,
            'specifications' => AgentSpec::query()
                ->orderBy('agent_name')
                ->orderByDesc('version')
                ->get(['agent_name', 'version', 'status']),
            'results' => EvaluationResult::query()
                ->with('evaluationCase:id,name')
                ->latest('id')
                ->limit(100)
                ->get(),
        ];
    }

    /**
     * @param  list<array{provider: string, model: string}>  $modelSelections
     * @param  list<int>  $specVersions
     * @param  Collection<int, EvaluationCase>  $cases
     * @return list<EvaluationResult>
     */
    public function run(User $user, Collection $cases, array $modelSelections, array $specVersions): array
    {
        $agentNames = $cases->pluck('agent_name')->unique();
        if ($agentNames->count() !== 1) {
            throw new InvalidArgumentException('An evaluation set must contain cases for one agent.');
        }
        $agentName = $agentNames->first();
        $specifications = AgentSpec::query()
            ->where('agent_name', $agentName)
            ->whereIn('version', $specVersions)
            ->get()
            ->keyBy('version');

        if ($specifications->count() !== count(array_unique($specVersions))) {
            throw new InvalidArgumentException('Choose valid prompt versions for this evaluation case agent.');
        }

        $providers = [];
        foreach ($modelSelections as $selection) {
            $key = $selection['provider'].'/'.$selection['model'];
            if (isset($providers[$key])) {
                throw new InvalidArgumentException('Choose each approved model only once.');
            }
            $providers[$key] = $this->providerResolver->resolveApprovedModel($user, $selection);
        }

        $this->auditLogService->record($user, 'evaluation.run.started', $cases->first(), [
            'case_count' => $cases->count(),
            'model_count' => count($providers),
            'prompt_version_count' => $specifications->count(),
        ], $user);

        $results = [];
        foreach ($cases as $case) {
            foreach ($specifications as $specification) {
                foreach ($providers as $selectionKey => $resolved) {
                    [$providerId] = explode('/', $selectionKey, 2);
                    $result = EvaluationResult::query()->create([
                        'user_id' => $user->id,
                        'evaluation_case_id' => $case->id,
                        'agent_name' => $case->agent_name,
                        'agent_spec_version' => $specification->version,
                        'provider' => $providerId,
                        'model' => $resolved['model'],
                        'status' => 'pending',
                    ]);
                    try {
                        EvaluateModelPromptJob::dispatch($result->id);
                    } catch (Exception $exception) {
                        $safeError = Arr::get($this->activityLogger->redactValue([
                            'message' => $exception->getMessage(),
                        ]), 'message', 'Evaluation could not be queued.');
                        $result->fill([
                            'status' => 'failed',
                            'error_message' => is_string($safeError) ? $safeError : 'Evaluation could not be queued.',
                        ])->save();
                        throw $exception;
                    }
                    $results[] = $result->fresh();
                }
            }
        }

        return $results;
    }

    public function executeResult(int $resultId): void
    {
        $result = EvaluationResult::withoutGlobalScopes()->findOrFail($resultId);
        if ($result->status !== 'pending') {
            return;
        }
        $user = User::withoutGlobalScopes()->findOrFail($result->user_id);
        $case = EvaluationCase::withoutGlobalScopes()->findOrFail($result->evaluation_case_id);
        if ($user->deleted_at !== null
            || $user->status !== STATUS_ACTIVE
            || ! $user->can('platform.manage')
            || $case->tenant_id !== $result->tenant_id) {
            $result->fill([
                'status' => 'failed',
                'error_message' => 'Evaluation was cancelled because account access or workspace ownership changed.',
            ])->save();

            return;
        }
        $specification = AgentSpec::query()
            ->where('agent_name', $result->agent_name)
            ->where('version', $result->agent_spec_version)
            ->firstOrFail();
        $resolved = $this->providerResolver->resolveApprovedModel($user, [
            'provider' => $result->provider,
            'model' => $result->model,
        ]);
        $result->status = 'running';
        $result->save();
        $this->evaluate($user, $case, $specification, $result, $resolved['provider']);
    }

    private function evaluate(
        User $user,
        EvaluationCase $case,
        AgentSpec $specification,
        EvaluationResult $result,
        LlmProvider $provider,
    ): EvaluationResult {
        $providerId = $result->provider;
        $model = $result->model;
        $pricing = collect($user->llmSettings?->model_pricing ?? [])
            ->first(fn (array $row): bool => ($row['provider'] ?? null) === $providerId
                && ($row['model'] ?? null) === $model);
        $startedAt = microtime(true);

        try {
            $response = $provider->chat([
                ['role' => 'system', 'content' => $specification->system_prompt],
                ['role' => 'user', 'content' => $case->prompt],
            ], [], [
                'model' => $model,
                'max_tokens' => min(max(1, (int) $specification->max_tokens), 2048),
                'temperature' => 0,
            ]);

            if ($response->toolCalls !== []) {
                throw new InvalidArgumentException('Evaluation calls cannot request tools.');
            }

            $output = $response->text ?? '';
            $score = $this->scorer->score($output, $case->reference_answer, $case->criteria ?? []);
            $usage = [
                'input_tokens' => (int) ($response->usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($response->usage['output_tokens'] ?? 0),
                'total_tokens' => (int) ($response->usage['total_tokens'] ?? 0),
            ];
            $costAmount = is_array($pricing)
                ? round((
                    ($usage['input_tokens'] * (float) $pricing['input_cost_per_million'])
                    + ($usage['output_tokens'] * (float) $pricing['output_cost_per_million'])
                ) / 1_000_000, 8)
                : null;

            $result->fill([
                'status' => 'completed',
                'score' => $score['score'],
                'metrics' => $score['metrics'],
                'output' => $output,
                'token_usage' => $usage,
                'cost_amount' => $costAmount,
                'cost_currency' => $costAmount === null ? null : $pricing['currency'],
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ])->save();
        } catch (Exception $exception) {
            $safeError = Arr::get($this->activityLogger->redactValue([
                'message' => $exception->getMessage(),
            ]), 'message', 'Evaluation request failed.');
            $result->fill([
                'status' => 'failed',
                'error_message' => is_string($safeError) ? $safeError : 'Evaluation request failed.',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ])->save();
        }

        return $result;
    }
}
