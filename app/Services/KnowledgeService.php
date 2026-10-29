<?php

namespace App\Services;

use App\Llm\EmbeddingCatalog;
use App\Llm\ProviderRequestException;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Models\UserMemory;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KnowledgeService
{
    use ResponseTrait;

    public function __construct(
        private readonly EmbeddingCatalog $embeddingCatalog,
        private readonly KnowledgeDocumentExtractor $extractor,
        private readonly KnowledgeFileManager $fileManager,
        private readonly KnowledgeSettingsService $knowledgeSettings,
    ) {}

    public function embeddingSettings(User $user): JsonResponse
    {
        $settings = $user->llmSettings;
        $providerId = $settings?->embedding_provider;

        return $this->success([
            'catalog' => $this->embeddingCatalog->publicCatalog(),
            'settings' => [
                'provider' => $providerId,
                'model' => $settings?->embedding_model,
                'key_configured' => is_string($providerId)
                    && $user->apiKeys()->where('provider', $providerId)->whereNotNull('api_key')->exists(),
            ],
        ]);
    }

    public function saveEmbeddingSettings(User $user, string $provider, string $model): JsonResponse
    {
        try {
            $this->embeddingCatalog->create($user, $provider, $model);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $settings = $user->llmSettings;
        if ($settings === null) {
            return $this->error('Save your main Brain settings before configuring embeddings.', 422);
        }
        $settings->update([
            'embedding_provider' => $provider,
            'embedding_model' => $model,
        ]);

        return $this->success([
            'settings' => ['provider' => $provider, 'model' => $model, 'key_configured' => true],
        ], UPDATED_SUCCESSFULLY);
    }

    public function upload(User $user, UploadedFile $file): JsonResponse
    {
        $settings = $user->llmSettings;
        if (! is_string($settings?->embedding_provider) || ! is_string($settings?->embedding_model)) {
            return $this->error('Configure an embedding provider and model before uploading files.', 422);
        }

        try {
            $embeddingProvider = $this->embeddingCatalog->create(
                $user,
                $settings->embedding_provider,
                $settings->embedding_model,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $storedFile = $this->fileManager->store($user, $file);
        $document = $user->knowledgeDocuments()->create([
            'original_name' => basename($file->getClientOriginalName()),
            'disk_path' => $storedFile['path'],
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => (int) $file->getSize(),
            'sha256' => $storedFile['sha256'],
            'status' => KNOWLEDGE_DOCUMENT_STATUS_PENDING,
        ]);

        try {
            $text = $this->extractor->extract($file);
            $processingSettings = $this->knowledgeSettings->current();
            $chunks = $this->chunks($text, $processingSettings);
            if (count($chunks) > $processingSettings['maximum_chunks_per_document']) {
                throw new RuntimeException('The file has more text than the configured indexing limit.');
            }
            $vectors = [];
            foreach (array_chunk($chunks, 32) as $batch) {
                array_push($vectors, ...$embeddingProvider->embed($batch, 'RETRIEVAL_DOCUMENT'));
            }
            if (count($vectors) !== count($chunks)) {
                throw new RuntimeException('The embedding service returned an incomplete result.');
            }

            DB::transaction(function () use ($document, $user, $settings, $chunks, $vectors): void {
                foreach ($chunks as $index => $content) {
                    $document->chunks()->create([
                        'user_id' => $user->id,
                        'tenant_id' => $user->tenant_id,
                        'chunk_index' => $index,
                        'content' => $content,
                        'embedding' => $vectors[$index],
                        'embedding_provider' => $settings->embedding_provider,
                        'embedding_model' => $settings->embedding_model,
                    ]);
                }
                $document->update([
                    'status' => KNOWLEDGE_DOCUMENT_STATUS_READY,
                    'chunk_count' => count($chunks),
                ]);
            });
        } catch (RuntimeException $exception) {
            $document->update([
                'status' => KNOWLEDGE_DOCUMENT_STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            return $this->error($exception->getMessage(), 422, [
                'document' => $this->documentData($document->refresh()),
            ]);
        }

        $document->refresh()->load('chunks:id,knowledge_document_id,embedding_provider,embedding_model');

        return $this->success([
            'document' => $this->documentData(
                $document,
                $settings->embedding_provider,
                $settings->embedding_model,
            ),
        ], CREATED_SUCCESSFULLY, 201);
    }

    public function documents(User $user): JsonResponse
    {
        $settings = $user->llmSettings;

        return $this->success([
            'documents' => $user->knowledgeDocuments()
                ->latest('id')
                ->with('chunks:id,knowledge_document_id,embedding_provider,embedding_model')
                ->get()
                ->map(fn (KnowledgeDocument $document): array => $this->documentData(
                    $document,
                    $settings?->embedding_provider,
                    $settings?->embedding_model,
                ))
                ->all(),
        ]);
    }

    public function reindexDocument(User $user, int $documentId): JsonResponse
    {
        $document = $user->knowledgeDocuments()
            ->where('status', KNOWLEDGE_DOCUMENT_STATUS_READY)
            ->findOrFail($documentId);
        $settings = $user->llmSettings;
        if (! is_string($settings?->embedding_provider) || ! is_string($settings?->embedding_model)) {
            return $this->error('Configure an embedding provider and model before reindexing files.', 422);
        }

        try {
            $embeddingProvider = $this->embeddingCatalog->create(
                $user,
                $settings->embedding_provider,
                $settings->embedding_model,
            );
            $path = $this->fileManager->absolutePath($document);
            $text = $this->extractor->extractStoredFile($path, $document->original_name);
            $processingSettings = $this->knowledgeSettings->current();
            $chunks = $this->chunks($text, $processingSettings);
            if (count($chunks) > $processingSettings['maximum_chunks_per_document']) {
                throw new RuntimeException('The file has more text than the configured indexing limit.');
            }

            $vectors = [];
            foreach (array_chunk($chunks, 32) as $batch) {
                array_push($vectors, ...$embeddingProvider->embed($batch, 'RETRIEVAL_DOCUMENT'));
            }
            if (count($vectors) !== count($chunks)) {
                throw new RuntimeException('The embedding service returned an incomplete result.');
            }

            DB::transaction(function () use ($document, $user, $settings, $chunks, $vectors): void {
                $document->chunks()->delete();
                foreach ($chunks as $index => $content) {
                    $document->chunks()->create([
                        'user_id' => $user->id,
                        'tenant_id' => $user->tenant_id,
                        'chunk_index' => $index,
                        'content' => $content,
                        'embedding' => $vectors[$index],
                        'embedding_provider' => $settings->embedding_provider,
                        'embedding_model' => $settings->embedding_model,
                    ]);
                }
                $document->update([
                    'status' => KNOWLEDGE_DOCUMENT_STATUS_READY,
                    'error_message' => null,
                    'chunk_count' => count($chunks),
                ]);
            });
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'document' => $this->documentData(
                $document->refresh()->load('chunks:id,knowledge_document_id,embedding_provider,embedding_model'),
                $settings->embedding_provider,
                $settings->embedding_model,
            ),
        ]);
    }

    public function deleteDocument(User $user, int $documentId): JsonResponse
    {
        $document = $user->knowledgeDocuments()->findOrFail($documentId);
        $this->fileManager->delete($document);
        $document->delete();

        return $this->success(['id' => $documentId], DELETED_SUCCESSFULLY);
    }

    public function downloadDocument(User $user, int $documentId): StreamedResponse
    {
        $document = $user->knowledgeDocuments()->findOrFail($documentId);

        return $this->fileManager->download($document);
    }

    /**
     * @param  list<int>  $documentIds
     */
    public function search(User $user, string $query, array $documentIds = [], int $limit = 5): JsonResponse
    {
        try {
            $results = $this->searchResults($user, $query, $documentIds, $limit);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (ProviderRequestException $exception) {
            return $this->error($exception->getMessage(), 502);
        }

        return $this->success(['results' => $results]);
    }

    /**
     * @param  list<int>  $documentIds
     * @return array{token: string, context: list<array{content: string, source: array<string, mixed>}>, sources: list<array<string, mixed>>}|null
     */
    public function createTaskPreview(User $user, string $prompt, array $documentIds): ?array
    {
        if ($documentIds === []) {
            return null;
        }
        DB::table('knowledge_search_previews')->where('expires_at', '<=', now())->delete();
        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));
        sort($documentIds);
        $readyIds = $user->knowledgeDocuments()
            ->where('status', KNOWLEDGE_DOCUMENT_STATUS_READY)
            ->whereIn('id', $documentIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        sort($readyIds);
        if ($readyIds !== $documentIds) {
            throw ValidationException::withMessages([
                'knowledge_document_ids' => 'Choose only your own indexed documents.',
            ]);
        }

        $context = array_map(
            static fn (array $result): array => [
                'content' => $result['content'],
                'source' => $result['source'],
            ],
            $this->searchResults($user, $prompt, $documentIds, 5),
        );
        $token = Str::random(64);
        DB::table('knowledge_search_previews')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'prompt_hash' => hash('sha256', $prompt),
            'document_ids' => json_encode($documentIds, JSON_THROW_ON_ERROR),
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'token' => $token,
            'context' => $context,
            'sources' => array_map(static fn (array $item): array => $item['source'], $context),
        ];
    }

    /**
     * @param  list<int>  $documentIds
     * @return list<array{content: string, source: array<string, mixed>}>
     */
    public function taskPreviewContext(User $user, string $prompt, array $documentIds, ?string $token): array
    {
        if ($documentIds === []) {
            return [];
        }
        if (! is_string($token) || $token === '') {
            throw ValidationException::withMessages([
                'knowledge_context_token' => 'Refresh the estimate for the selected files before starting this task.',
            ]);
        }

        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));
        sort($documentIds);
        $ownedReadyIds = $user->knowledgeDocuments()
            ->where('status', KNOWLEDGE_DOCUMENT_STATUS_READY)
            ->whereIn('id', $documentIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        sort($ownedReadyIds);
        if ($ownedReadyIds !== $documentIds) {
            throw ValidationException::withMessages([
                'knowledge_document_ids' => 'Choose only your own indexed documents.',
            ]);
        }
        $preview = DB::table('knowledge_search_previews')
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('prompt_hash', hash('sha256', $prompt))
            ->where('expires_at', '>', now())
            ->first();
        $storedDocumentIds = $preview === null ? null : json_decode($preview->document_ids, true, 512, JSON_THROW_ON_ERROR);
        if ($preview === null || $storedDocumentIds !== $documentIds) {
            throw ValidationException::withMessages([
                'knowledge_context_token' => 'The file search preview expired or no longer matches the task. Re-estimate before continuing.',
            ]);
        }

        $context = json_decode($preview->context, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($context)) {
            throw new RuntimeException('The selected-file preview could not be loaded safely.');
        }

        return $context;
    }

    public function consumeTaskPreview(User $user, ?string $token): void
    {
        if ($token !== null && $token !== '') {
            DB::table('knowledge_search_previews')
                ->where('user_id', $user->id)
                ->where('token_hash', hash('sha256', $token))
                ->delete();
        }
    }

    /**
     * @param  list<int>  $documentIds
     * @return list<array<string, mixed>>
     */
    private function searchResults(User $user, string $query, array $documentIds, int $limit): array
    {
        $settings = $user->llmSettings;
        if (! is_string($settings?->embedding_provider) || ! is_string($settings?->embedding_model)) {
            throw new InvalidArgumentException('Configure an embedding provider and model before searching files.');
        }
        $provider = $this->embeddingCatalog->create($user, $settings->embedding_provider, $settings->embedding_model);
        $vectors = $provider->embed([$query], 'RETRIEVAL_QUERY');
        $queryVector = $vectors[0] ?? [];
        if ($queryVector === []) {
            throw new RuntimeException('The embedding service returned an empty search vector.');
        }

        $chunks = KnowledgeChunk::query()
            ->where('user_id', $user->id)
            ->where('embedding_provider', $settings->embedding_provider)
            ->where('embedding_model', $settings->embedding_model)
            ->whereHas('document', static function ($builder) use ($documentIds): void {
                $builder->where('status', KNOWLEDGE_DOCUMENT_STATUS_READY);
                if ($documentIds !== []) {
                    $builder->whereIn('id', $documentIds);
                }
            })
            ->with('document:id,original_name')
            ->get();

        return $chunks
            ->map(static function (KnowledgeChunk $chunk) use ($queryVector): array {
                return [
                    'chunk' => $chunk,
                    'score' => self::cosineSimilarity($queryVector, $chunk->embedding),
                ];
            })
            ->filter(static fn (array $result): bool => $result['score'] > 0)
            ->sortByDesc('score')
            ->take(max(1, min($limit, 10)))
            ->values()
            ->map(static fn (array $result): array => [
                'content' => $result['chunk']->content,
                'relevance' => round($result['score'], 4),
                'source' => [
                    'document_id' => $result['chunk']->knowledge_document_id,
                    'name' => $result['chunk']->document->original_name,
                    'chunk' => $result['chunk']->chunk_index + 1,
                ],
            ])->all();
    }

    public function createMemory(
        User $user,
        int $type,
        ?string $label,
        string $content,
        ?int $expiresInDays,
        int $scopeType = USER_MEMORY_SCOPE_USER,
        ?int $taskId = null,
    ): JsonResponse {
        if ($scopeType === USER_MEMORY_SCOPE_TASK) {
            $user->tasks()->findOrFail($taskId);
        } elseif ($scopeType !== USER_MEMORY_SCOPE_USER || $taskId !== null) {
            throw ValidationException::withMessages(['scope_type' => 'The requested memory scope is not available.']);
        }

        $expiresAt = $type === USER_MEMORY_TYPE_SHORT_TERM && $expiresInDays !== null
            ? now()->addDays($expiresInDays)
            : null;
        $memory = DB::transaction(function () use ($user, $type, $label, $content, $expiresAt, $scopeType, $taskId): UserMemory {
            $memory = $user->memories()->create([
                'scope_type' => $scopeType,
                'task_id' => $scopeType === USER_MEMORY_SCOPE_TASK ? $taskId : null,
                'status' => USER_MEMORY_STATUS_ACTIVE,
                'created_by_user_id' => $user->id,
                'source_type' => USER_MEMORY_SOURCE_USER_INPUT,
                'type' => $type,
                'label' => $label,
                'content' => trim($content),
                'expires_at' => $expiresAt,
            ]);

            if (is_string($label) && trim($label) !== '') {
                $previousMemories = $user->memories()
                    ->where('scope_type', $scopeType)
                    ->where('task_id', $scopeType === USER_MEMORY_SCOPE_TASK ? $taskId : null)
                    ->where('status', USER_MEMORY_STATUS_ACTIVE)
                    ->whereRaw('LOWER(label) = ?', [mb_strtolower(trim($label))])
                    ->where('id', '<>', $memory->id)
                    ->lockForUpdate()
                    ->get();

                foreach ($previousMemories as $previousMemory) {
                    $previousMemory->update([
                        'status' => USER_MEMORY_STATUS_SUPERSEDED,
                        'superseded_by_id' => $memory->id,
                    ]);
                }
            }

            return $memory;
        });

        return $this->success(['memory' => $this->memoryData($memory)], CREATED_SUCCESSFULLY, 201);
    }

    public function memories(User $user, ?int $taskId = null): JsonResponse
    {
        $query = $user->memories();
        if ($taskId !== null) {
            $user->tasks()->findOrFail($taskId);
            $query->where(function ($scope) use ($taskId): void {
                $scope->where(function ($personal): void {
                    $personal->where('scope_type', USER_MEMORY_SCOPE_USER)
                        ->whereNull('task_id');
                })->orWhere(function ($taskScope) use ($taskId): void {
                    $taskScope->where('scope_type', USER_MEMORY_SCOPE_TASK)
                        ->where('task_id', $taskId);
                });
            });
        } else {
            $query->where('scope_type', USER_MEMORY_SCOPE_USER)->whereNull('task_id');
        }
        $query->where(function ($visibility): void {
            $visibility->where('status', USER_MEMORY_STATUS_SUPERSEDED)
                ->orWhere(function ($active): void {
                    $active->where('status', USER_MEMORY_STATUS_ACTIVE)
                        ->where(function ($expiry): void {
                            $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
        });

        return $this->success([
            'memories' => $query
                ->latest('id')
                ->get()
                ->map(fn (UserMemory $memory): array => $this->memoryData($memory))
                ->all(),
        ]);
    }

    public function deleteMemory(User $user, int $memoryId): JsonResponse
    {
        $memory = $user->memories()->findOrFail($memoryId);
        if ($memory->status !== USER_MEMORY_STATUS_ACTIVE) {
            throw ValidationException::withMessages(['memory' => 'Only active memories can be deleted.']);
        }
        $memory->delete();

        return $this->success(['id' => $memoryId], DELETED_SUCCESSFULLY);
    }

    /**
     * @param  list<int>  $memoryIds
     * @return list<array<string, mixed>>
     */
    public function selectedMemoryContext(User $user, array $memoryIds): array
    {
        if ($memoryIds === []) {
            return [];
        }

        $memories = $user->memories()
            ->where('scope_type', USER_MEMORY_SCOPE_USER)
            ->whereNull('task_id')
            ->where('status', USER_MEMORY_STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereIn('id', $memoryIds)
            ->orderBy('id')
            ->get();

        if ($memories->count() !== count(array_unique($memoryIds))) {
            throw ValidationException::withMessages([
                'memory_ids' => 'One or more selected memories are unavailable, expired, or outside your personal scope.',
            ]);
        }

        $context = $memories->map(static fn (UserMemory $memory): array => [
            'memory_id' => $memory->id,
            'label' => $memory->label,
            'content' => $memory->content,
            'source' => 'user_memory',
            'created_at' => $memory->created_at?->toIso8601String(),
        ])->all();

        if (mb_strlen(json_encode($context, JSON_THROW_ON_ERROR)) > 10000) {
            throw ValidationException::withMessages([
                'memory_ids' => 'Selected memories exceed the maximum task context size.',
            ]);
        }

        return $context;
    }

    /**
     * @param  list<array<string, mixed>>  $context
     */
    public function memoryContextHash(array $context): string
    {
        return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<string>
     */
    private function chunks(string $text, array $settings): array
    {
        $maximum = $settings['chunk_characters'];
        $overlap = min($settings['chunk_overlap_characters'], $maximum - 1);
        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $content = trim(mb_substr($text, $offset, $maximum));
            if ($content !== '') {
                $chunks[] = $content;
            }
            $offset += max(1, $maximum - $overlap);
        }

        return $chunks;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private static function cosineSimilarity(array $left, array $right): float
    {
        if (count($left) !== count($right) || $left === []) {
            return 0.0;
        }
        $dot = 0.0;
        $leftMagnitude = 0.0;
        $rightMagnitude = 0.0;
        foreach ($left as $index => $value) {
            $dot += $value * $right[$index];
            $leftMagnitude += $value * $value;
            $rightMagnitude += $right[$index] * $right[$index];
        }
        if ($leftMagnitude <= 0 || $rightMagnitude <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($leftMagnitude) * sqrt($rightMagnitude));
    }

    /**
     * @return array<string, mixed>
     */
    private function documentData(
        KnowledgeDocument $document,
        ?string $embeddingProvider = null,
        ?string $embeddingModel = null,
    ): array {
        $data = $document->only([
            'id',
            'original_name',
            'mime_type',
            'size_bytes',
            'status',
            'error_message',
            'chunk_count',
            'created_at',
        ]);
        $data['matches_embedding_settings'] = $embeddingProvider !== null
            && $embeddingModel !== null
            && $document->status === KNOWLEDGE_DOCUMENT_STATUS_READY
            && $document->chunks->contains(static fn (KnowledgeChunk $chunk): bool => (
                $chunk->embedding_provider === $embeddingProvider
                && $chunk->embedding_model === $embeddingModel
            ));

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function memoryData(UserMemory $memory): array
    {
        $data = $memory->only([
            'id',
            'scope_type',
            'task_id',
            'type',
            'label',
            'content',
            'status',
            'created_by_user_id',
            'source_type',
            'superseded_by_id',
            'expires_at',
            'created_at',
        ]);
        $data['expired'] = $memory->status === USER_MEMORY_STATUS_ACTIVE
            && $memory->expires_at !== null
            && $memory->expires_at->isPast();

        return $data;
    }
}
