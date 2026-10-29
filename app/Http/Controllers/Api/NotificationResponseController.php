<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationResponseToken;
use App\Services\AccessPolicyEngine;
use App\Services\ToolApprovalService;
use App\Tools\ToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NotificationResponseController extends Controller
{
    public function show(string $token)
    {
        $responseToken = $this->findToken($token);
        $decision = htmlspecialchars($responseToken->decision, ENT_QUOTES, 'UTF-8');

        return response(
            '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            .'<title>Confirm approval response</title><body><main><h1>Confirm '.ucfirst($decision).'</h1>'
            .'<p>Opening this page does not submit your response. Confirm below to send a one-time decision.</p>'
            .'<form method="post"><input type="hidden" name="_token" value="'.csrf_token().'">'
            .'<button type="submit" name="confirm" value="1">Confirm '.ucfirst($decision).'</button></form></main></body></html>',
        )->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function decide(Request $request, string $token, ToolApprovalService $approvals)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        $responseToken = $this->findToken($token);
        $decision = DB::transaction(function () use ($responseToken): array {
            $lockedToken = NotificationResponseToken::query()->lockForUpdate()->findOrFail($responseToken->id);
            if ($lockedToken->consumed_at !== null
                || $lockedToken->expires_at->isPast()
                || $lockedToken->approval?->status !== APPROVAL_STATUS_PENDING) {
                throw new HttpException(410, 'This one-time response link is no longer valid.');
            }

            $approval = $lockedToken->approval;
            $toolName = $approval?->payload['tool_name'] ?? null;
            if (! is_string($toolName)) {
                throw new HttpException(410, 'This approval is no longer available.');
            }
            $tool = app(ToolRegistry::class)->get($toolName);
            if ($tool->minimumTrustLevel() >= AGENT_TRUST_LEVEL_WRITE
                || $tool->requiresApproval()
                || app(AccessPolicyEngine::class)->isNeverAuto($toolName)) {
                throw new HttpException(403, 'Higher-risk approvals must be decided in the authenticated application.');
            }

            $lockedToken->update(['consumed_at' => now()]);

            return [
                'user' => $lockedToken->user,
                'approval_id' => $approval->id,
                'approve' => $lockedToken->decision === 'approve',
            ];
        });

        $approvals->decide($decision['user'], $decision['approval_id'], $decision['approve'], 'One-time notification response link.');

        return response()->view('notification-response-complete', ['decision' => $decision['approve'] ? 'approved' : 'rejected']);
    }

    private function findToken(string $token): NotificationResponseToken
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new HttpException(404, 'Response link not found.');
        }

        $responseToken = NotificationResponseToken::query()
            ->with(['approval.task', 'user'])
            ->where('token_hash', hash('sha256', $token))
            ->first();
        if ($responseToken === null
            || $responseToken->consumed_at !== null
            || $responseToken->expires_at->isPast()
            || $responseToken->approval?->status !== APPROVAL_STATUS_PENDING) {
            throw new HttpException(410, 'This one-time response link is no longer valid.');
        }

        return $responseToken;
    }
}
