<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = $request->user()->auditLogs()->orderByDesc('id');

        if (isset($data['before_id'])) {
            $query->where('id', '<', $data['before_id']);
        }

        $entries = $query->limit($data['limit'] ?? 50)->get();

        return response()->json([
            'audit_logs' => $entries->map(static fn ($entry): array => [
                'id' => $entry->id,
                'actor' => [
                    'type' => $entry->actor_type,
                    'id' => $entry->actor_id,
                ],
                'action' => $entry->action,
                'subject_type' => $entry->subject_type,
                'subject_id' => $entry->subject_id,
                'metadata' => $entry->metadata ?? [],
                'created_at' => $entry->created_at,
            ])->all(),
        ]);
    }
}
