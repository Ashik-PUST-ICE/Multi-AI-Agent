<?php

namespace Tests\Feature;

use App\Llm\ProviderCatalog;
use App\Models\KnowledgeDocument;
use App\Models\Permission;
use App\Models\User;
use App\Services\KnowledgeDocumentExtractor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class KnowledgeApiTest extends TestCase
{
    use DatabaseMigrations;

    public function test_private_documents_are_extracted_chunked_embedded_searchable_and_owner_scoped(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://api.openai.com/v1/embeddings' => static function ($request) {
                $input = $request->data()['input'];

                return Http::response([
                    'data' => array_map(
                        static fn (string $text, int $index): array => [
                            'index' => $index,
                            'embedding' => str_contains(strtolower($text), 'climate') ? [1.0, 0.0] : [0.0, 1.0],
                        ],
                        $input,
                        array_keys($input),
                    ),
                ]);
            },
        ]);
        $owner = $this->userWithEmbeddingSettings();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($owner);

        $upload = $this->postJson('/api/v1/knowledge/documents', [
            'file' => UploadedFile::fake()->createWithContent(
                'climate.txt',
                'Climate evidence and observed temperature patterns.',
            ),
        ])->assertCreated()
            ->assertJsonPath('document.status', KNOWLEDGE_DOCUMENT_STATUS_READY)
            ->assertJsonPath('document.chunk_count', 1);
        $documentId = $upload->json('document.id');
        $document = KnowledgeDocument::query()->findOrFail($documentId);
        Storage::disk('local')->assertExists($document->disk_path);

        $this->postJson('/api/v1/knowledge/search', [
            'query' => 'climate',
            'document_ids' => [$documentId],
        ])->assertOk()
            ->assertJsonPath('results.0.source.name', 'climate.txt')
            ->assertJsonPath('results.0.source.chunk', 1)
            ->assertJsonPath('results.0.content', 'Climate evidence and observed temperature patterns.');

        Sanctum::actingAs($otherUser);
        $this->getJson("/api/v1/knowledge/documents/{$documentId}/download")->assertNotFound();
        $this->deleteJson("/api/v1/knowledge/documents/{$documentId}")->assertNotFound();
        $this->assertDatabaseHas('knowledge_documents', ['id' => $documentId, 'user_id' => $owner->id]);

        Sanctum::actingAs($owner);
        $file = $owner->knowledgeDocuments()->findOrFail($documentId);
        Storage::disk('local')->assertExists($file->disk_path);
        $this->assertSame(
            'Climate evidence and observed temperature patterns.',
            Storage::disk('local')->get($file->disk_path),
        );
        $this->get("/api/v1/knowledge/documents/{$documentId}/download")->assertOk();
        $this->deleteJson("/api/v1/knowledge/documents/{$documentId}")->assertOk();
        Storage::disk('local')->assertMissing($file->disk_path);
    }

    public function test_upload_requires_embedding_settings_and_rejects_unsupported_document_types(): void
    {
        Storage::fake('local');
        $user = $this->userWithEmbeddingSettings(false);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/knowledge/documents', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Text'),
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Configure an embedding provider and model before uploading files.');

        $this->putJson('/api/v1/knowledge/embedding-settings', [
            'provider' => ProviderCatalog::ANTHROPIC,
            'model' => 'claude-3-5-sonnet-20241022',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/knowledge/documents', [
            'file' => UploadedFile::fake()->createWithContent('payload.exe', 'not allowed'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_users_can_create_list_expire_and_delete_their_own_memories(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/memories', [
            'type' => USER_MEMORY_TYPE_SHORT_TERM,
            'label' => 'Temporary note',
            'content' => 'Remember this for a short time.',
            'expires_in_days' => 1,
        ])->assertCreated()
            ->assertJsonPath('memory.type', USER_MEMORY_TYPE_SHORT_TERM);
        $longTerm = $this->postJson('/api/v1/memories', [
            'type' => USER_MEMORY_TYPE_LONG_TERM,
            'label' => 'Language preference',
            'content' => 'Prefer Bengali answers.',
        ])->assertCreated()
            ->assertJsonPath('memory.type', USER_MEMORY_TYPE_LONG_TERM);
        $longTermId = $longTerm->json('memory.id');

        $user->memories()->where('type', USER_MEMORY_TYPE_SHORT_TERM)->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/memories')
            ->assertOk()
            ->assertJsonCount(1, 'memories')
            ->assertJsonPath('memories.0.id', $longTermId);

        Sanctum::actingAs($otherUser);
        $this->deleteJson("/api/v1/memories/{$longTermId}")->assertNotFound();
        $this->assertDatabaseHas('user_memories', ['id' => $longTermId, 'user_id' => $user->id]);
    }

    public function test_memory_provenance_supersession_and_task_scope_are_owner_governed(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'Memory scope test',
            'description' => 'Task data stays task-scoped.',
        ]);
        $otherTask = $user->tasks()->create([
            'title' => 'Different task',
            'description' => 'No task memory crosses this boundary.',
        ]);
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/memories', [
            'type' => USER_MEMORY_TYPE_LONG_TERM,
            'label' => 'Preferred language',
            'content' => 'Use English.',
        ])->assertCreated()
            ->assertJsonPath('memory.scope_type', USER_MEMORY_SCOPE_USER)
            ->assertJsonPath('memory.created_by_user_id', $user->id)
            ->assertJsonPath('memory.source_type', USER_MEMORY_SOURCE_USER_INPUT);
        $firstId = $first->json('memory.id');

        $replacement = $this->postJson('/api/v1/memories', [
            'type' => USER_MEMORY_TYPE_LONG_TERM,
            'label' => 'preferred language',
            'content' => 'Use Bengali.',
        ])->assertCreated();
        $replacementId = $replacement->json('memory.id');
        $this->assertDatabaseHas('user_memories', [
            'id' => $firstId,
            'status' => USER_MEMORY_STATUS_SUPERSEDED,
            'superseded_by_id' => $replacementId,
            'content' => 'Use English.',
        ]);
        $this->assertDatabaseHas('user_memories', [
            'id' => $replacementId,
            'status' => USER_MEMORY_STATUS_ACTIVE,
        ]);

        $taskMemory = $this->postJson('/api/v1/memories', [
            'type' => USER_MEMORY_TYPE_SHORT_TERM,
            'scope_type' => USER_MEMORY_SCOPE_TASK,
            'task_id' => $task->id,
            'label' => 'Task-only detail',
            'content' => 'Only use in this task.',
            'expires_in_days' => 1,
        ])->assertCreated()
            ->assertJsonPath('memory.task_id', $task->id);
        $taskMemoryId = $taskMemory->json('memory.id');

        $this->getJson("/api/v1/memories?task_id={$task->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $taskMemoryId]);
        $this->getJson('/api/v1/memories')
            ->assertOk()
            ->assertJsonMissing(['id' => $taskMemoryId]);
        $this->getJson("/api/v1/memories?task_id={$otherTask->id}")
            ->assertOk()
            ->assertJsonMissing(['id' => $taskMemoryId]);

        Sanctum::actingAs($otherUser);
        $this->getJson("/api/v1/memories?task_id={$task->id}")->assertNotFound();
        $this->postJson('/api/v1/memories', [
            'type' => USER_MEMORY_TYPE_LONG_TERM,
            'scope_type' => USER_MEMORY_SCOPE_TASK,
            'task_id' => $task->id,
            'content' => 'Cross-owner task data.',
        ])->assertUnprocessable();
    }

    public function test_organization_and_shared_memory_scopes_remain_disabled(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        foreach ([USER_MEMORY_SCOPE_ORGANIZATION, USER_MEMORY_SCOPE_SHARED] as $scopeType) {
            $this->postJson('/api/v1/memories', [
                'type' => USER_MEMORY_TYPE_LONG_TERM,
                'scope_type' => $scopeType,
                'label' => 'Private by default',
                'content' => 'Unconfigured sharing must not be accepted.',
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('scope_type');
        }

        $this->assertDatabaseCount('user_memories', 0);
    }

    public function test_expired_or_unowned_memories_cannot_be_selected_for_task_context(): void
    {
        $user = $this->userWithEmbeddingSettings();
        $otherUser = User::factory()->create();
        $owned = $user->memories()->create([
            'type' => USER_MEMORY_TYPE_LONG_TERM,
            'label' => 'Owned memory',
            'content' => 'Only selected when explicitly requested.',
        ]);
        $expired = $user->memories()->create([
            'type' => USER_MEMORY_TYPE_SHORT_TERM,
            'label' => 'Expired memory',
            'content' => 'Do not retrieve.',
            'expires_at' => now()->subMinute(),
        ]);
        $foreign = $otherUser->memories()->create([
            'type' => USER_MEMORY_TYPE_LONG_TERM,
            'label' => 'Foreign memory',
            'content' => 'Must remain private.',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Use my selected note.',
            'memory_ids' => [$owned->id],
        ])->assertOk()
            ->assertJsonPath('memory_preview.memories.0.id', $owned->id);

        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Try expired memory.',
            'memory_ids' => [$expired->id],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Try another owner memory.',
            'memory_ids' => [$foreign->id],
        ])->assertUnprocessable();
    }

    public function test_task_estimate_previews_selected_file_context_and_task_uses_the_bound_preview(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://api.openai.com/v1/embeddings' => static function ($request) {
                $input = $request->data()['input'];

                return Http::response([
                    'data' => array_map(
                        static fn (string $text): array => ['embedding' => [1.0, 0.0]],
                        $input,
                    ),
                ]);
            },
        ]);
        Queue::fake();
        $user = $this->userWithEmbeddingSettings();
        Sanctum::actingAs($user);
        $upload = $this->postJson('/api/v1/knowledge/documents', [
            'file' => UploadedFile::fake()->createWithContent('brief.txt', 'Project brief context for the team.'),
        ])->assertCreated();
        $documentId = $upload->json('document.id');

        $estimate = $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Summarize the project brief.',
            'knowledge_document_ids' => [$documentId],
        ])->assertOk()
            ->assertJsonPath('knowledge_preview.sources.0.name', 'brief.txt');
        $token = $estimate->json('knowledge_preview.token');

        $this->postJson('/api/v1/tasks', [
            'title' => 'Summarize brief',
            'prompt' => 'Summarize the project brief.',
            'knowledge_document_ids' => [$documentId],
            'knowledge_context_token' => $token,
        ])->assertAccepted()
            ->assertJsonPath('task.knowledge_sources.0.name', 'brief.txt');

        $run = $user->tasks()->firstOrFail()->agentRuns()->firstOrFail();
        $this->assertSame(
            'Project brief context for the team.',
            $run->input['payload']['context']['knowledge_context'][0]['content'],
        );
        $this->assertDatabaseMissing('knowledge_search_previews', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_documents_can_be_reindexed_for_a_new_embedding_model(): void
    {
        Storage::fake('local');
        $embeddingFails = false;
        Http::fake([
            'https://api.openai.com/v1/embeddings' => static function ($request) use (&$embeddingFails) {
                if ($embeddingFails) {
                    return Http::response(['error' => ['message' => 'Embedding unavailable.']], 503);
                }

                return Http::response([
                    'data' => array_map(
                        static fn (): array => ['embedding' => [1.0, 0.0]],
                        $request->data()['input'],
                    ),
                ]);
            },
        ]);
        $user = $this->userWithEmbeddingSettings();
        Sanctum::actingAs($user);

        $upload = $this->postJson('/api/v1/knowledge/documents', [
            'file' => UploadedFile::fake()->createWithContent('brief.txt', 'Project brief context for the team.'),
        ])->assertCreated();
        $documentId = $upload->json('document.id');
        $this->assertTrue($upload->json('document.matches_embedding_settings'));

        $this->putJson('/api/v1/knowledge/embedding-settings', [
            'provider' => ProviderCatalog::OPENAI,
            'model' => 'text-embedding-3-large',
        ])->assertOk();
        $this->getJson('/api/v1/knowledge/documents')
            ->assertOk()
            ->assertJsonPath('documents.0.matches_embedding_settings', false);

        $embeddingFails = true;
        $this->postJson("/api/v1/knowledge/documents/{$documentId}/reindex")->assertUnprocessable();
        $this->assertDatabaseHas('knowledge_chunks', [
            'knowledge_document_id' => $documentId,
            'embedding_provider' => ProviderCatalog::OPENAI,
            'embedding_model' => 'text-embedding-3-small',
        ]);
        $this->assertDatabaseCount('knowledge_chunks', 1);

        $embeddingFails = false;
        $this->postJson("/api/v1/knowledge/documents/{$documentId}/reindex")
            ->assertOk()
            ->assertJsonPath('document.matches_embedding_settings', true);
        $this->assertDatabaseHas('knowledge_chunks', [
            'knowledge_document_id' => $documentId,
            'embedding_provider' => ProviderCatalog::OPENAI,
            'embedding_model' => 'text-embedding-3-large',
        ]);
        $this->assertDatabaseCount('knowledge_chunks', 1);
    }

    public function test_spreadsheet_values_are_extracted_and_ocr_failures_are_reported(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://api.openai.com/v1/embeddings' => static function ($request) {
                return Http::response([
                    'data' => array_map(
                        static fn (): array => ['embedding' => [1.0, 0.0]],
                        $request->data()['input'],
                    ),
                ]);
            },
        ]);
        $user = $this->userWithEmbeddingSettings();
        Sanctum::actingAs($user);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'knowledge-xlsx-');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Project budget');
        $spreadsheet->getActiveSheet()->setCellValue('B1', 42);

        try {
            (new Xlsx($spreadsheet))->save($temporaryPath);
            $upload = $this->postJson('/api/v1/knowledge/documents', [
                'file' => new UploadedFile(
                    $temporaryPath,
                    'report.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true,
                ),
            ])->assertCreated();

            $this->assertStringContainsString(
                'Project budget | 42',
                $user->knowledgeDocuments()->findOrFail($upload->json('document.id'))->chunks()->firstOrFail()->content,
            );

            $pdfUpload = $this->postJson('/api/v1/knowledge/documents', [
                'file' => UploadedFile::fake()->createWithContent(
                    'report.pdf',
                    $this->minimalPdf('Project PDF excerpt'),
                ),
            ])->assertCreated();
            $this->assertStringContainsString(
                'Project PDF excerpt',
                $user->knowledgeDocuments()->findOrFail($pdfUpload->json('document.id'))->chunks()->firstOrFail()->content,
            );
        } finally {
            $spreadsheet->disconnectWorksheets();
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        Process::fake(static fn () => Process::result('Recognized image text.'));
        $this->assertSame(
            'Recognized image text.',
            app(KnowledgeDocumentExtractor::class)->extract(
                UploadedFile::fake()->createWithContent('scan.png', 'test image bytes'),
            ),
        );

        Process::fake(static fn () => Process::result('', 'OCR unavailable', 1));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Image text extraction failed. Install and configure Tesseract OCR, then retry.');
        app(KnowledgeDocumentExtractor::class)->extract(
            UploadedFile::fake()->createWithContent('scan.png', 'test image bytes'),
        );
    }

    public function test_malformed_pdf_upload_is_marked_failed_instead_of_staying_pending(): void
    {
        Storage::fake('local');
        $user = $this->userWithEmbeddingSettings();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/knowledge/documents', [
            'file' => UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.4\nnot a valid PDF"),
        ])->assertUnprocessable();

        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $response->json('document.id'),
            'user_id' => $user->id,
            'status' => KNOWLEDGE_DOCUMENT_STATUS_FAILED,
        ]);
    }

    public function test_admin_knowledge_settings_are_database_backed_and_enforce_upload_and_chunk_limits(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://api.openai.com/v1/embeddings' => static function ($request) {
                return Http::response([
                    'data' => array_map(
                        static fn (): array => ['embedding' => [1.0, 0.0]],
                        $request->data()['input'],
                    ),
                ]);
            },
        ]);
        $this->seed(DatabaseSeeder::class);
        $user = $this->userWithEmbeddingSettings();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/knowledge-settings')->assertForbidden();

        $user->givePermissionTo(Permission::query()->where('name', 'knowledge_settings.manage')->firstOrFail());
        $this->getJson('/api/v1/admin/knowledge-settings')
            ->assertOk()
            ->assertJsonPath('settings.maximum_upload_kilobytes', 10240)
            ->assertJsonPath('settings.chunk_characters', 1400);

        $this->putJson('/api/v1/admin/knowledge-settings', [
            'maximum_upload_kilobytes' => 1,
            'maximum_extracted_characters' => 10000,
            'maximum_chunks_per_document' => 10,
            'chunk_characters' => 200,
            'chunk_overlap_characters' => 0,
        ])->assertOk()
            ->assertJsonPath('settings.maximum_upload_kilobytes', 1);
        $this->assertDatabaseHas('knowledge_system_settings', [
            'id' => 1,
            'maximum_upload_kilobytes' => 1,
        ]);
        $this->postJson('/api/v1/knowledge/documents', [
            'file' => UploadedFile::fake()->createWithContent('large.txt', str_repeat('x', 2048)),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->putJson('/api/v1/admin/knowledge-settings', [
            'maximum_upload_kilobytes' => 10,
            'maximum_extracted_characters' => 10000,
            'maximum_chunks_per_document' => 10,
            'chunk_characters' => 200,
            'chunk_overlap_characters' => 0,
        ])->assertOk();
        $upload = $this->postJson('/api/v1/knowledge/documents', [
            'file' => UploadedFile::fake()->createWithContent('chunks.txt', str_repeat('a', 450)),
        ])->assertCreated();
        $document = $user->knowledgeDocuments()->findOrFail($upload->json('document.id'));
        $this->assertSame(3, $document->chunk_count);

        $this->putJson('/api/v1/admin/knowledge-settings', [
            'maximum_upload_kilobytes' => 10,
            'maximum_extracted_characters' => 10000,
            'maximum_chunks_per_document' => 10,
            'chunk_characters' => 200,
            'chunk_overlap_characters' => 200,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('chunk_overlap_characters');
    }

    private function userWithEmbeddingSettings(bool $withKey = true): User
    {
        $catalog = app(ProviderCatalog::class);
        $models = array_keys($catalog->provider(ProviderCatalog::OPENAI)['models']);
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => ProviderCatalog::OPENAI,
            'model' => $models[0],
            'approved_models' => [
                ['provider' => ProviderCatalog::OPENAI, 'model' => $models[0]],
                ['provider' => ProviderCatalog::OPENAI, 'model' => $models[1]],
            ],
            'agent_models' => [],
            'embedding_provider' => $withKey ? ProviderCatalog::OPENAI : null,
            'embedding_model' => $withKey ? 'text-embedding-3-small' : null,
        ]);
        if ($withKey) {
            $user->apiKeys()->create([
                'agent_name' => 'Orchestrator',
                'provider' => ProviderCatalog::OPENAI,
                'api_key' => 'test-key-not-a-secret',
            ]);
        }

        return $user;
    }

    private function minimalPdf(string $text): string
    {
        $escapedText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $content = "BT /F1 12 Tf 10 100 Td ({$escapedText}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $crossReferenceOffset = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$crossReferenceOffset}\n%%EOF";
    }
}
