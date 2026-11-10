<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateCodingProposalRequest;
use App\Models\CodingChangeProposal;
use App\Models\User;
use App\Services\CodingProposalSecretScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CodingProposalController extends Controller
{
    public function __construct(
        private readonly CodingProposalSecretScanner $secretScanner,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $proposals = $request->user()->codingChangeProposals()
            ->latest('id')
            ->paginate(30);

        return response()->json([
            'proposals' => $proposals->getCollection()
                ->map(fn (CodingChangeProposal $proposal): array => $this->proposalSummary($proposal))
                ->values(),
            'pagination' => [
                'current_page' => $proposals->currentPage(),
                'last_page' => $proposals->lastPage(),
                'per_page' => $proposals->perPage(),
                'total' => $proposals->total(),
            ],
        ]);
    }

    public function store(CreateCodingProposalRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $task = isset($data['task_id']) ? $user->tasks()->findOrFail($data['task_id']) : null;
        $findings = $this->secretScanner->findingsInFields([
            $data['title'],
            $data['plan'],
            $data['diff'],
        ]);
        if ($findings !== []) {
            return response()->json([
                'message' => 'The proposal was not stored because the secret scan found credential-like content.',
                'secret_scan' => ['status' => 'blocked', 'findings' => $findings],
            ], 422);
        }

        $proposal = DB::transaction(function () use ($data, $task, $user): CodingChangeProposal {
            return $user->codingChangeProposals()->create([
                'task_id' => $task?->id,
                'workspace_label' => $data['workspace_label'],
                'title' => $data['title'],
                'plan' => $data['plan'],
                'diff' => $data['diff'],
                'diff_sha256' => hash('sha256', $data['diff']),
                'status' => 'pending',
                'secret_scan_status' => 'passed',
            ]);
        });

        return response()->json(['proposal' => $this->proposalData($proposal)], 201);
    }

    public function show(Request $request, int $proposal): JsonResponse
    {
        $proposal = $request->user()->codingChangeProposals()->findOrFail($proposal);

        return response()->json(['proposal' => $this->proposalData($proposal)]);
    }

    public function approve(Request $request, int $proposal): JsonResponse
    {
        return $this->decide($request, $proposal, 'approved');
    }

    public function reject(Request $request, int $proposal): JsonResponse
    {
        return $this->decide($request, $proposal, 'rejected');
    }

    private function decide(Request $request, int $proposalId, string $decision): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = DB::transaction(function () use ($decision, $proposalId, $user): ?CodingChangeProposal {
            $proposal = $user->codingChangeProposals()->lockForUpdate()->findOrFail($proposalId);
            if ($proposal->status !== 'pending') {
                return null;
            }

            if (! hash_equals($proposal->diff_sha256, hash('sha256', $proposal->diff))) {
                return null;
            }
            $findings = $this->secretScanner->findingsInFields([
                $proposal->title,
                $proposal->plan,
                $proposal->diff,
            ]);
            if ($findings !== []) {
                return null;
            }

            $proposal->forceFill([
                'status' => $decision,
                'decision_at' => now(),
                'secret_scan_status' => 'passed',
            ])->save();

            return $proposal;
        });

        if ($updated === null) {
            return response()->json([
                'message' => 'The proposal is no longer pending or failed its integrity and secret checks.',
            ], 409);
        }

        return response()->json([
            'proposal' => $this->proposalData($updated),
            'notice' => 'This decision records review only. It does not apply files or execute commands.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalData(CodingChangeProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'task_id' => $proposal->task_id,
            'workspace_label' => $proposal->workspace_label,
            'title' => $proposal->title,
            'plan' => $proposal->plan,
            'diff' => $proposal->diff,
            'diff_sha256' => $proposal->diff_sha256,
            'status' => $proposal->status,
            'secret_scan_status' => $proposal->secret_scan_status,
            'decision_at' => $proposal->decision_at?->toISOString(),
            'created_at' => $proposal->created_at?->toISOString(),
            'updated_at' => $proposal->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalSummary(CodingChangeProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'task_id' => $proposal->task_id,
            'workspace_label' => $proposal->workspace_label,
            'title' => $proposal->title,
            'status' => $proposal->status,
            'secret_scan_status' => $proposal->secret_scan_status,
            'created_at' => $proposal->created_at?->toISOString(),
            'updated_at' => $proposal->updated_at?->toISOString(),
        ];
    }
}
