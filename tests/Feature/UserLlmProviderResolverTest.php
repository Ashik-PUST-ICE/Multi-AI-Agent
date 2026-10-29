<?php

namespace Tests\Feature;

use App\Agents\OrchestratorAgent;
use App\Llm\ChatResponse;
use App\Llm\LlmProvider;
use App\Llm\ProviderCatalog;
use App\Llm\ProviderFactory;
use App\Llm\ProviderRequestException;
use App\Llm\UserLlmProviderResolver;
use App\Models\User;
use App\Services\ModelRouter;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UserLlmProviderResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_skeptic_is_routed_to_a_different_approved_model_from_writer(): void
    {
        $catalog = app(ProviderCatalog::class);
        $providerId = ProviderCatalog::OPENAI;
        $models = array_keys($catalog->provider($providerId)['models']);
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $models[0],
            'approved_models' => [
                ['provider' => $providerId, 'model' => $models[0]],
                ['provider' => $providerId, 'model' => $models[1]],
                ['provider' => $providerId, 'model' => $models[2]],
            ],
            'fallback_provider' => $providerId,
            'fallback_model' => $models[1],
            'agent_models' => [],
        ]);
        $resolver = app(UserLlmProviderResolver::class);

        $writer = $resolver->selectedModel($user, 'Writer');
        $skeptic = $resolver->selectedModel($user, 'Skeptic');
        $fixedSkeptic = $resolver->selectedModel(
            $user,
            'Skeptic',
            TASK_MODE_SANDBOX,
            AGENT_BRAIN_MODE_FIXED,
        );

        $this->assertSame($models[0], $writer['model']);
        $this->assertSame($models[2], $skeptic['model']);
        $this->assertSame($models[2], $fixedSkeptic['model']);
        $this->assertSame(AGENT_BRAIN_MODE_AUTO, $fixedSkeptic['brain_mode']);
        $this->assertStringContainsString('distinct user-approved model', $skeptic['reason']);
        $this->assertSame([], $skeptic['fallback_candidates']);
    }

    public function test_skeptic_fails_closed_when_no_different_approved_model_exists(): void
    {
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => ProviderCatalog::OPENAI,
            'model' => 'gpt-4o-mini',
            'approved_models' => [
                ['provider' => ProviderCatalog::OPENAI, 'model' => 'gpt-4o-mini'],
            ],
            'agent_models' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a different approved model from Writer');
        app(UserLlmProviderResolver::class)->selectedModel($user, 'Skeptic');
    }

    public function test_agent_uses_its_user_approved_model_and_saved_credentials(): void
    {
        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $models = array_keys($catalog->provider($providerId)['models']);
        $primaryModel = $models[0];
        $agentModel = $models[1];
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $primaryModel,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $primaryModel],
                ['provider' => $providerId, 'model' => $agentModel],
            ],
            'agent_models' => [
                'Orchestrator' => ['provider' => $providerId, 'model' => $agentModel],
            ],
            'agent_brains' => ['Orchestrator' => AGENT_BRAIN_MODE_CUSTOM],
            'fallback_provider' => $providerId,
            'fallback_model' => $primaryModel,
        ]);
        $user->apiKeys()->create([
            'provider' => $providerId,
            'api_key' => 'user-owned-test-key',
        ]);
        $provider = new class implements LlmProvider
        {
            public array $options = [];

            public array $messages = [];

            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                $this->options = $options;
                $this->messages = $messages;

                return new ChatResponse('Configured model response', [], [], 'stop');
            }
        };
        $providerFactory = \Mockery::mock(ProviderFactory::class);
        $providerFactory->shouldReceive('create')
            ->once()
            ->with($providerId, $agentModel, 'user-owned-test-key', null, $user)
            ->andReturn($provider);
        $providerFactory->shouldReceive('create')
            ->once()
            ->with($providerId, $primaryModel, 'user-owned-test-key', null, $user)
            ->andReturn($provider);
        $resolver = new UserLlmProviderResolver(app(ModelRouter::class), $providerFactory, $catalog);
        $agent = new OrchestratorAgent(
            $provider,
            new ToolRegistry,
            TASK_MODE_SANDBOX,
            $resolver,
        );

        $result = $agent->runForUser($user, 'Plan this task');

        $this->assertSame('Configured model response', $result['text']);
        $this->assertSame($agentModel, $provider->options['model']);
        $this->assertGreaterThan(0, $provider->options['max_tokens']);
        $this->assertStringNotContainsString('user-owned-test-key', json_encode($provider->messages, JSON_THROW_ON_ERROR));
        $this->assertLessThan(2000, $provider->options['max_tokens']);
    }

    public function test_agent_refuses_a_model_removed_from_user_approval(): void
    {
        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $models = array_keys($catalog->provider($providerId)['models']);
        $user = User::factory()->create();
        $settings = $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $models[0],
            'approved_models' => [
                ['provider' => $providerId, 'model' => $models[0]],
            ],
            'agent_models' => [
                'Orchestrator' => ['provider' => $providerId, 'model' => $models[1]],
            ],
            'agent_brains' => ['Orchestrator' => AGENT_BRAIN_MODE_CUSTOM],
        ]);
        $providerFactory = \Mockery::mock(ProviderFactory::class);
        $resolver = new UserLlmProviderResolver(app(ModelRouter::class), $providerFactory, $catalog);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The selected agent model is not approved in Settings.');
        $resolver->resolve($user, 'Orchestrator');
    }

    public function test_retryable_provider_failure_uses_one_fallback_for_response_and_schema_correction(): void
    {
        $catalog = app(ProviderCatalog::class);
        $providerId = ProviderCatalog::OPENAI;
        $models = array_keys($catalog->provider($providerId)['models']);
        $primaryModel = $models[0];
        $fallbackModel = $models[1];
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $primaryModel,
            'fallback_provider' => $providerId,
            'fallback_model' => $fallbackModel,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $primaryModel],
                ['provider' => $providerId, 'model' => $fallbackModel],
            ],
        ]);
        $user->apiKeys()->create([
            'provider' => $providerId,
            'api_key' => 'user-owned-test-key',
        ]);
        $primaryProvider = new class implements LlmProvider
        {
            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                throw new ProviderRequestException('Temporary provider error.', true);
            }
        };
        $fallbackProvider = new class implements LlmProvider
        {
            public int $calls = 0;

            public array $models = [];

            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                $this->calls++;
                $this->models[] = $options['model'];

                return new ChatResponse(
                    $this->calls === 1 ? '' : '{"text":"Fallback corrected response"}',
                    [],
                    [],
                    'stop',
                );
            }
        };
        $providerFactory = \Mockery::mock(ProviderFactory::class);
        $providerFactory->shouldReceive('create')
            ->once()
            ->with($providerId, $primaryModel, 'user-owned-test-key', null, $user)
            ->andReturn($primaryProvider);
        $providerFactory->shouldReceive('create')
            ->once()
            ->with($providerId, $fallbackModel, 'user-owned-test-key', null, $user)
            ->andReturn($fallbackProvider);
        $resolver = new UserLlmProviderResolver(app(ModelRouter::class), $providerFactory, $catalog);
        $agent = new OrchestratorAgent(
            $primaryProvider,
            new ToolRegistry,
            TASK_MODE_SANDBOX,
            $resolver,
        );

        $result = $agent->runForUser($user, 'Plan this task');

        $this->assertSame('Fallback corrected response', $result['text']);
        $this->assertSame([$fallbackModel, $fallbackModel], $fallbackProvider->models);
    }

    public function test_non_retryable_provider_failure_does_not_call_fallback(): void
    {
        $catalog = app(ProviderCatalog::class);
        $providerId = ProviderCatalog::OPENAI;
        $models = array_keys($catalog->provider($providerId)['models']);
        $primaryModel = $models[0];
        $fallbackModel = $models[1];
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $primaryModel,
            'fallback_provider' => $providerId,
            'fallback_model' => $fallbackModel,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $primaryModel],
                ['provider' => $providerId, 'model' => $fallbackModel],
            ],
        ]);
        $user->apiKeys()->create([
            'provider' => $providerId,
            'api_key' => 'user-owned-test-key',
        ]);
        $primaryProvider = new class implements LlmProvider
        {
            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                throw new ProviderRequestException('Invalid provider request.', false);
            }
        };
        $fallbackProvider = new class implements LlmProvider
        {
            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                throw new \RuntimeException('Fallback must not be called for a non-retryable error.');
            }
        };
        $providerFactory = \Mockery::mock(ProviderFactory::class);
        $providerFactory->shouldReceive('create')
            ->once()
            ->with($providerId, $primaryModel, 'user-owned-test-key', null, $user)
            ->andReturn($primaryProvider);
        $providerFactory->shouldReceive('create')
            ->once()
            ->with($providerId, $fallbackModel, 'user-owned-test-key', null, $user)
            ->andReturn($fallbackProvider);
        $resolver = new UserLlmProviderResolver(app(ModelRouter::class), $providerFactory, $catalog);
        $agent = new OrchestratorAgent(
            $primaryProvider,
            new ToolRegistry,
            TASK_MODE_SANDBOX,
            $resolver,
        );

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('Invalid provider request.');
        $agent->runForUser($user, 'Plan this task');
    }

    public function test_compatible_provider_requires_a_base_url_instead_of_an_api_key(): void
    {
        $user = User::factory()->create();
        $resolver = app(UserLlmProviderResolver::class);

        $this->assertFalse($resolver->hasConfiguredCredentials($user, ProviderCatalog::OPENAI_COMPATIBLE));

        $user->apiKeys()->create([
            'provider' => ProviderCatalog::OPENAI_COMPATIBLE,
            'base_url' => 'https://compatible.example/v1',
        ]);

        $this->assertTrue($resolver->hasConfiguredCredentials($user, ProviderCatalog::OPENAI_COMPATIBLE));
    }
}
