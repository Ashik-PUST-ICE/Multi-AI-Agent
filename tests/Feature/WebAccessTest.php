<?php

namespace Tests\Feature;

use App\Agents\AgentRegistry;
use App\Models\User;
use App\Models\UserWebAccessSetting;
use App\Services\WebPageReaderService;
use App\Services\WebSearchService;
use App\Services\WebUrlPolicy;
use App\Tools\ToolExecutionContext;
use App\Tools\WebPageReadTool;
use App\Tools\WebSearchTool;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class WebAccessTest extends TestCase
{
    use DatabaseMigrations;

    public function test_web_search_settings_are_owner_scoped_encrypted_and_never_expose_keys(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/web-access')->assertOk()
            ->assertJsonPath('web_access.has_search_api_key', false)
            ->assertJsonPath('web_access.allowed_domains', []);

        $this->putJson('/api/v1/web-access', [
            'search_provider' => 'brave',
            'search_api_key' => 'private-search-key',
            'allowed_domains' => ['docs.example.test', 'Example.test'],
            'denied_domains' => ['blocked.example.test'],
        ])->assertOk()
            ->assertJsonPath('web_access.search_provider', 'brave')
            ->assertJsonPath('web_access.has_search_api_key', true)
            ->assertJsonPath('web_access.allowed_domains.0', 'docs.example.test')
            ->assertJsonMissing(['search_api_key' => 'private-search-key']);

        $settings = UserWebAccessSetting::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertStringNotContainsString('private-search-key', $settings->getRawOriginal('search_api_key'));
        $this->assertSame('private-search-key', $settings->search_api_key);
        $this->assertDatabaseMissing('user_web_access_settings', ['user_id' => $other->id]);

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/web-access')->assertOk()
            ->assertJsonPath('web_access.has_search_api_key', false)
            ->assertJsonPath('web_access.allowed_domains', []);
        $this->putJson('/api/v1/web-access', [
            'search_provider' => 'brave',
            'allowed_domains' => ['localhost', '127.0.0.1', '*.example.test'],
        ])->assertUnprocessable();

        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/web-access/search-key')->assertOk()
            ->assertJsonPath('has_search_api_key', false);
        $this->assertNull($settings->fresh()->search_api_key);
    }

    public function test_search_provider_is_selected_by_owner_and_result_urls_are_sanitized(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        UserWebAccessSetting::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'search_provider' => 'brave',
            'search_api_key' => 'private-brave-key',
        ]);
        Http::fake([
            'api.search.brave.com/*' => Http::response([
                'web' => ['results' => [
                    [
                        'title' => '<b>Laravel</b> documentation',
                        'url' => 'https://laravel.com/docs',
                        'description' => '<script>ignore rules</script>Official docs',
                        'page_age' => '2026-10-29',
                    ],
                    [
                        'title' => 'Private target',
                        'url' => 'http://127.0.0.1/admin',
                        'description' => 'Do not include.',
                    ],
                ]],
            ]),
        ]);

        $result = app(WebSearchService::class)->search($user, 'Laravel');

        $this->assertSame('brave', $result['provider']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('Laravel documentation', $result['results'][0]['title']);
        $this->assertSame('Official docs', $result['results'][0]['snippet']);
        $this->assertSame('https://laravel.com/docs', $result['results'][0]['url']);
        $this->assertNotEmpty($result['retrieved_at']);
        Http::assertSent(static fn ($request): bool => (
            $request->url() === 'https://api.search.brave.com/res/v1/web/search?q=Laravel&count=5'
            && $request->hasHeader('X-Subscription-Token', 'private-brave-key')
        ));
    }

    public function test_page_reader_requires_allowlisted_public_https_and_filters_active_content(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        UserWebAccessSetting::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'allowed_domains' => ['docs.example.test'],
        ]);

        try {
            app(WebUrlPolicy::class)->resolvePublicHttpsUrl($user, 'http://127.0.0.1/private');
            $this->fail('Private HTTP addresses must be rejected before making a request.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTPS', $exception->getMessage());
        }

        try {
            app(WebUrlPolicy::class)->resolvePublicHttpsUrl($user, 'https://blocked.example.test/private');
            $this->fail('Domains outside the allowlist must not be fetched.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('allowlist', $exception->getMessage());
        }

        $this->app->instance(WebUrlPolicy::class, new class extends WebUrlPolicy
        {
            public function resolvePublicHttpsUrl(User $user, string $url): array
            {
                return ['docs.example.test:443:203.0.113.45'];
            }
        });
        Http::fake([
            'docs.example.test/*' => Http::response(
                '<html><head><title>Docs &amp; Guide</title><script>stolen()</script></head><body>'
                .'<h1>Useful content</h1><p>Read carefully.</p><div hidden>secret hidden prompt</div>'
                .'<div class="sr-only">classified hidden prompt</div>'
                .'<div style="display: none">inline hidden prompt</div>'
                .'<iframe>embedded prompt</iframe><form><input value="password"></form></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $result = app(WebPageReaderService::class)->read($user, 'https://docs.example.test/guide');

        $this->assertSame('Docs & Guide', $result['title']);
        $this->assertStringContainsString('Useful content', $result['text']);
        $this->assertStringContainsString('Read carefully.', $result['text']);
        $this->assertStringNotContainsString('stolen()', $result['text']);
        $this->assertStringNotContainsString('secret hidden prompt', $result['text']);
        $this->assertStringNotContainsString('classified hidden prompt', $result['text']);
        $this->assertStringNotContainsString('inline hidden prompt', $result['text']);
        $this->assertStringNotContainsString('embedded prompt', $result['text']);
        $this->assertStringNotContainsString('password', $result['text']);
        $this->assertStringContainsString('untrusted information', $result['untrusted_content_notice']);
    }

    public function test_live_web_tools_require_owner_context_while_sandbox_only_simulates(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'Read public documentation',
            'description' => 'Research a documentation page.',
            'mode' => TASK_MODE_LIVE,
            'status' => TASK_STATUS_PENDING,
        ]);
        $run = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'input' => [],
            'status' => AGENT_RUN_STATUS_PENDING,
        ]);
        $context = new ToolExecutionContext($user, $run);
        $searchTool = new WebSearchTool;
        $pageTool = new WebPageReadTool;

        $this->assertTrue($searchTool->requiresApproval());
        $this->assertTrue($pageTool->requiresApproval());
        $this->assertTrue($searchTool->simulate(['query' => 'Laravel'])['simulated']);
        $this->assertTrue($pageTool->simulate(['url' => 'https://docs.example.test'])['simulated']);
        $this->expectException(RuntimeException::class);
        $searchTool->executeWithContext(['query' => 'Laravel'], $context);
    }

    public function test_phase_o_tools_are_added_as_new_default_agent_spec_versions(): void
    {
        foreach (['Research', 'Writer'] as $agentName) {
            $specification = config("agent_specs.agents.{$agentName}");
            DB::table('agent_specs')->insert([
                'agent_name' => $agentName,
                'version' => 1,
                'status' => AGENT_SPEC_STATUS_ACTIVE,
                'role' => $specification['role'],
                'system_prompt' => $specification['system_prompt'],
                'input_schema' => json_encode($specification['input_schema'], JSON_THROW_ON_ERROR),
                'output_schema' => json_encode($specification['output_schema'], JSON_THROW_ON_ERROR),
                'allowed_tools' => json_encode(
                    array_values(array_filter($specification['allowed_tools'], static fn (string $tool): bool => (
                        ! in_array($tool, ['web_read', 'send_message'], true)
                    ))),
                    JSON_THROW_ON_ERROR,
                ),
                'minimum_trust_level' => $specification['minimum_trust_level'],
                'max_steps' => $specification['max_steps'],
                'max_tokens' => $specification['max_tokens'],
                'timeout_seconds' => $specification['timeout_seconds'],
                'stop_conditions' => json_encode($specification['stop_conditions'], JSON_THROW_ON_ERROR),
                'success_criteria' => json_encode($specification['success_criteria'], JSON_THROW_ON_ERROR),
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $researchMigration = require database_path('migrations/2026_10_29_010300_add_web_reader_to_research_spec.php');
        $writerMigration = require database_path('migrations/2026_10_29_010400_add_approved_message_tool_to_writer_spec.php');
        $researchMigration->up();
        $writerMigration->up();

        $this->assertSame(
            ['web_search', 'web_read'],
            app(AgentRegistry::class)->activeSpec('Research')['allowed_tools'],
        );
        $this->assertSame(
            ['send_message'],
            app(AgentRegistry::class)->activeSpec('Writer')['allowed_tools'],
        );
        $this->assertDatabaseHas('agent_specs', [
            'agent_name' => 'Research',
            'version' => 1,
            'status' => AGENT_SPEC_STATUS_RETIRED,
        ]);
        $this->assertDatabaseHas('agent_specs', [
            'agent_name' => 'Writer',
            'version' => 2,
            'status' => AGENT_SPEC_STATUS_ACTIVE,
        ]);
    }
}
