<?php

namespace Tests\Feature;

use App\Agents\OrchestratorAgent;
use App\Llm\ChatResponse;
use App\Llm\LlmProvider;
use App\Llm\ProviderCatalog;
use App\Llm\ProviderFactory;
use App\Llm\UserLlmProviderResolver;
use App\Models\User;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UserLlmProviderResolverTest extends TestCase
{
    use RefreshDatabase;

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
        ]);
        $user->apiKeys()->create([
            'provider' => $providerId,
            'api_key' => 'user-owned-test-key',
        ]);
        $provider = new class implements LlmProvider
        {
            public array $options = [];

            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                $this->options = $options;

                return new ChatResponse('Configured model response', [], [], 'stop');
            }
        };
        $providerFactory = \Mockery::mock(ProviderFactory::class);
        $providerFactory->shouldReceive('create')
            ->once()
            ->with($providerId, $agentModel, 'user-owned-test-key', null, $user)
            ->andReturn($provider);
        $resolver = new UserLlmProviderResolver($catalog, $providerFactory);
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
        ]);
        $providerFactory = \Mockery::mock(ProviderFactory::class);
        $resolver = new UserLlmProviderResolver($catalog, $providerFactory);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The selected agent model is not approved in Settings.');
        $resolver->resolve($user, 'Orchestrator');
    }
}
