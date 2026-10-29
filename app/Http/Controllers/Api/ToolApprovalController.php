<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BatchDecideToolApprovalRequest;
use App\Http\Requests\Api\DecideToolApprovalRequest;
use App\Services\ToolApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ToolApprovalController extends Controller
{
    public function __construct(
        private readonly ToolApprovalService $toolApprovalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->toolApprovalService->pendingFor($request->user());
    }

    public function approve(DecideToolApprovalRequest $request, int $approval): JsonResponse
    {
        $validatedData = $request->validated();

        return $this->toolApprovalService->decide(
            $request->user(),
            $approval,
            true,
            $validatedData['decision_note'] ?? null,
        );
    }

    public function reject(DecideToolApprovalRequest $request, int $approval): JsonResponse
    {
        $validatedData = $request->validated();

        return $this->toolApprovalService->decide(
            $request->user(),
            $approval,
            false,
            $validatedData['decision_note'] ?? null,
        );
    }

    public function decideBatch(BatchDecideToolApprovalRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->toolApprovalService->decideBatch(
            $request->user(),
            $data['approval_ids'],
            $data['decision'] === 'approve',
            $data['decision_note'] ?? null,
        );
    }
}
