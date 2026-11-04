<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvaluationCase;
use App\Services\EvaluationService;
use App\Services\PlatformAdminDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformAdminController extends Controller
{
    public function __construct(
        private readonly EvaluationService $evaluationService,
        private readonly PlatformAdminDashboardService $dashboardService,
    ) {}

    public function dashboard(): JsonResponse
    {
        return response()->json($this->dashboardService->summary());
    }

    public function evaluationData(Request $request): JsonResponse
    {
        $data = $this->evaluationService->data($request->user());

        return response()->json([
            'cases' => $data['cases']->map(static fn (EvaluationCase $case): array => [
                'id' => $case->id,
                'name' => $case->name,
                'agent_name' => $case->agent_name,
                'criteria' => $case->criteria ?? [],
            ])->all(),
            'approved_models' => $data['approved_models'],
            'specifications' => $data['specifications'],
            'results' => $data['results']->map(static fn ($result): array => [
                'id' => $result->id,
                'case_name' => $result->evaluationCase?->name,
                'agent_name' => $result->agent_name,
                'agent_spec_version' => $result->agent_spec_version,
                'provider' => $result->provider,
                'model' => $result->model,
                'status' => $result->status,
                'score' => $result->score,
                'metrics' => $result->metrics ?? [],
                'output' => $result->output,
                'error_message' => $result->error_message,
                'token_usage' => $result->token_usage ?? [],
                'cost_amount' => $result->cost_amount,
                'cost_currency' => $result->cost_currency,
                'duration_ms' => $result->duration_ms,
                'created_at' => $result->created_at,
            ])->all(),
        ]);
    }

    public function createEvaluationCase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'agent_name' => ['required', 'string', 'max:80', 'exists:agent_specs,agent_name'],
            'prompt' => ['required', 'string', 'max:4000'],
            'reference_answer' => ['nullable', 'string', 'max:4000'],
            'criteria' => ['sometimes', 'array', 'max:20'],
            'criteria.*' => ['required', 'string', 'min:1', 'max:120'],
        ]);
        $criteria = array_values(array_filter(array_map('trim', $data['criteria'] ?? [])));
        if (trim((string) ($data['reference_answer'] ?? '')) === '' && $criteria === []) {
            throw ValidationException::withMessages([
                'criteria' => 'Add a reference answer or at least one measurable criterion.',
            ]);
        }
        $case = $this->evaluationService->createCase($request->user(), [
            ...$data,
            'criteria' => $criteria,
        ]);

        return response()->json(['case' => $case->only(['id', 'name', 'agent_name', 'criteria'])], 201);
    }

    public function runEvaluation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'case_ids' => ['required', 'array', 'min:1', 'max:5'],
            'case_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'models' => ['required', 'array', 'min:1', 'max:3'],
            'models.*.provider' => ['required', 'string', 'max:64'],
            'models.*.model' => ['required', 'string', 'max:128'],
            'models.*' => ['array:provider,model', 'distinct'],
            'spec_versions' => ['required', 'array', 'min:1', 'max:2'],
            'spec_versions.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $cases = EvaluationCase::query()->whereIn('id', $data['case_ids'])->get();
        if ($cases->count() !== count($data['case_ids'])) {
            throw ValidationException::withMessages([
                'case_ids' => 'Choose evaluation cases available in this workspace.',
            ]);
        }
        if ($cases->pluck('agent_name')->unique()->count() !== 1) {
            throw ValidationException::withMessages([
                'case_ids' => 'Every case in a comparison set must belong to the same agent.',
            ]);
        }
        try {
            $results = $this->evaluationService->run(
                $request->user(),
                $cases,
                $data['models'],
                $data['spec_versions'],
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'models' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'results' => array_map(static fn ($result): array => [
                'id' => $result->id,
                'agent_spec_version' => $result->agent_spec_version,
                'provider' => $result->provider,
                'model' => $result->model,
                'status' => $result->status,
                'score' => $result->score,
                'metrics' => $result->metrics ?? [],
                'output' => $result->output,
                'error_message' => $result->error_message,
                'token_usage' => $result->token_usage ?? [],
                'cost_amount' => $result->cost_amount,
                'cost_currency' => $result->cost_currency,
                'duration_ms' => $result->duration_ms,
            ], $results),
        ]);
    }
}
