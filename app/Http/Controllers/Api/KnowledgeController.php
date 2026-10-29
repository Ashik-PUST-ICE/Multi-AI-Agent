<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SearchKnowledgeRequest;
use App\Http\Requests\Api\StoreUserMemoryRequest;
use App\Http\Requests\Api\UpdateEmbeddingSettingsRequest;
use App\Http\Requests\Api\UploadKnowledgeDocumentRequest;
use App\Services\KnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KnowledgeController extends Controller
{
    public function __construct(private readonly KnowledgeService $knowledgeService) {}

    public function documents(Request $request): JsonResponse
    {
        return $this->knowledgeService->documents($request->user());
    }

    public function upload(UploadKnowledgeDocumentRequest $request): JsonResponse
    {
        return $this->knowledgeService->upload($request->user(), $request->file('file'));
    }

    public function deleteDocument(Request $request, int $document): JsonResponse
    {
        return $this->knowledgeService->deleteDocument($request->user(), $document);
    }

    public function reindexDocument(Request $request, int $document): JsonResponse
    {
        return $this->knowledgeService->reindexDocument($request->user(), $document);
    }

    public function downloadDocument(Request $request, int $document): StreamedResponse
    {
        return $this->knowledgeService->downloadDocument($request->user(), $document);
    }

    public function embeddingSettings(Request $request): JsonResponse
    {
        return $this->knowledgeService->embeddingSettings($request->user());
    }

    public function saveEmbeddingSettings(UpdateEmbeddingSettingsRequest $request): JsonResponse
    {
        return $this->knowledgeService->saveEmbeddingSettings(
            $request->user(),
            $request->validated('provider'),
            $request->validated('model'),
        );
    }

    public function search(SearchKnowledgeRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->knowledgeService->search(
            $request->user(),
            $data['query'],
            $data['document_ids'] ?? [],
            $data['limit'] ?? 5,
        );
    }

    public function memories(Request $request): JsonResponse
    {
        return $this->knowledgeService->memories($request->user());
    }

    public function storeMemory(StoreUserMemoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->knowledgeService->createMemory(
            $request->user(),
            $data['type'],
            $data['label'] ?? null,
            $data['content'],
            $data['expires_in_days'] ?? null,
        );
    }

    public function deleteMemory(Request $request, int $memory): JsonResponse
    {
        return $this->knowledgeService->deleteMemory($request->user(), $memory);
    }
}
