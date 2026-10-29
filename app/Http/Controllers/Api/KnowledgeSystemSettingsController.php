<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateKnowledgeSystemSettingsRequest;
use App\Services\KnowledgeSettingsService;
use Illuminate\Http\JsonResponse;

class KnowledgeSystemSettingsController extends Controller
{
    public function __construct(private readonly KnowledgeSettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['settings' => $this->settings->current()]);
    }

    public function update(UpdateKnowledgeSystemSettingsRequest $request): JsonResponse
    {
        return response()->json([
            'settings' => $this->settings->update($request->validated()),
        ]);
    }
}
