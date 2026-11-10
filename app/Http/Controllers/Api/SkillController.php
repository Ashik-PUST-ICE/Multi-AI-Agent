<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EvaluateUserSkillRequest;
use App\Http\Requests\Api\SetUserSkillEnabledRequest;
use App\Http\Requests\Api\SetUserSkillEvaluationPolicyRequest;
use App\Http\Requests\Api\StoreUserSkillRequest;
use App\Http\Requests\Api\StoreUserSkillTestCaseRequest;
use App\Http\Requests\Api\UpdateUserSkillRequest;
use App\Http\Requests\Api\VerifyUserSkillRequest;
use App\Services\SkillDraftService;
use App\Services\SkillEvaluationService;
use App\Services\SkillRegistryService;
use App\Services\SkillUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SkillController extends Controller
{
    public function __construct(
        private readonly SkillRegistryService $registry,
        private readonly SkillEvaluationService $evaluations,
        private readonly SkillDraftService $drafts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'skills' => $this->registry->index($request->user()),
            'maximum_matches_per_task' => (int) config('skills.maximum_matches', 2),
            'external_installation_enabled' => true,
            'external_installation_requires_owner_action' => true,
            'remote_fetch_enabled' => false,
            'scripts_enabled' => false,
        ]);
    }

    public function store(StoreUserSkillRequest $request): JsonResponse
    {
        return response()->json([
            'skill' => $this->registry->create($request->user(), $request->validated()),
        ], 201);
    }

    public function suggestFromTask(Request $request, int $task): JsonResponse
    {
        return response()->json($this->drafts->suggestFromTask($request->user(), $task));
    }

    public function show(Request $request, int $skill): JsonResponse
    {
        return response()->json([
            'skill' => $this->registry->show($request->user(), $skill),
        ]);
    }

    public function update(UpdateUserSkillRequest $request, int $skill): JsonResponse
    {
        return response()->json([
            'skill' => $this->registry->update($request->user(), $skill, $request->validated()),
        ]);
    }

    public function verify(VerifyUserSkillRequest $request, int $skill): JsonResponse
    {
        return response()->json([
            'skill' => $this->registry->verify($request->user(), $skill, $request->validated()),
        ]);
    }

    public function setEnabled(SetUserSkillEnabledRequest $request, int $skill): JsonResponse
    {
        return response()->json([
            'skill' => $this->registry->setEnabled(
                $request->user(),
                $skill,
                (bool) $request->validated('enabled'),
            ),
        ]);
    }

    public function rollback(Request $request, int $skill, int $version): JsonResponse
    {
        if ($request->boolean('confirm') !== true) {
            throw ValidationException::withMessages([
                'confirm' => 'Confirm the rollback before creating a new skill version.',
            ]);
        }

        return response()->json([
            'skill' => $this->registry->rollback($request->user(), $skill, $version),
        ]);
    }

    public function evaluationData(Request $request, int $skill): JsonResponse
    {
        return response()->json([
            'evaluation' => $this->evaluations->data($request->user(), $skill),
        ]);
    }

    public function usage(Request $request, int $skill): JsonResponse
    {
        return response()->json([
            'usage' => app(SkillUsageService::class)->summary($request->user(), $skill),
        ]);
    }

    public function storeTestCase(StoreUserSkillTestCaseRequest $request, int $skill): JsonResponse
    {
        return response()->json([
            'test_case' => $this->evaluations->createTestCase($request->user(), $skill, $request->validated()),
        ], 201);
    }

    public function updateTestCase(StoreUserSkillTestCaseRequest $request, int $skill, int $testCase): JsonResponse
    {
        return response()->json([
            'test_case' => $this->evaluations->updateTestCase(
                $request->user(),
                $skill,
                $testCase,
                $request->validated(),
            ),
        ]);
    }

    public function deleteTestCase(Request $request, int $skill, int $testCase): JsonResponse
    {
        $this->evaluations->deleteTestCase($request->user(), $skill, $testCase);

        return response()->json(['deleted' => true]);
    }

    public function setEvaluationPolicy(SetUserSkillEvaluationPolicyRequest $request, int $skill): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'policy' => $this->evaluations->setPolicy(
                $request->user(),
                $skill,
                (bool) $data['auto_disable'],
                isset($data['minimum_score']) ? (float) $data['minimum_score'] : null,
            ),
        ]);
    }

    public function evaluate(EvaluateUserSkillRequest $request, int $skill): JsonResponse
    {
        $request->validated();

        return response()->json([
            'evaluation' => $this->evaluations->evaluate($request->user(), $skill),
        ]);
    }
}
