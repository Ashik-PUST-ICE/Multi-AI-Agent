<?php

namespace Tests\Feature;

use App\Agents\ComplexityRouter;
use App\Llm\ChatResponse;
use App\Llm\ProviderCatalog;
use App\Llm\UserLlmProviderResolver;
use App\Models\User;
use App\Services\TaskCostEstimator;
use App\Services\TaskTokenBudgetService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class TaskRoutingAndBudgetTest extends TestCase
{
    use DatabaseMigrations;

    public function test_router_defaults_to_one_agent_unless_multiple_roles_are_explicit(): void
    {
        $router = app(ComplexityRouter::class);

        $this->assertSame(['Research'], $router->route('Research a topic.')['agent_sequence']);
        $this->assertSame(['Writer'], $router->route('Write a short welcome message.')['agent_sequence']);
        $this->assertSame(
            ['Orchestrator', 'Research', 'Writer', 'Reviewer'],
            $router->route('Research and write a summary with an independent review.')['agent_sequence'],
        );
    }

    public function test_cost_estimate_uses_user_entered_model_pricing_and_never_fabricates_rates(): void
    {
        $user = $this->userWithApprovedModel();
        $estimator = app(TaskCostEstimator::class);
        $unpricedEstimate = $estimator->estimate($user, 'Write a concise response.', ['Writer']);

        $this->assertSame('unavailable', $unpricedEstimate['status']);
        $this->assertTrue($unpricedEstimate['can_complete']);
        $this->assertNull($unpricedEstimate['maximum_cost']);

        $settings = $user->llmSettings;
        $settings->model_pricing = [[
            'provider' => $settings->provider,
            'model' => $settings->model,
            'currency' => 'USD',
            'input_cost_per_million' => 1,
            'output_cost_per_million' => 2,
        ]];
        $settings->save();
        $pricedEstimate = $estimator->estimate($user, 'Write a concise response.', ['Writer']);

        $this->assertSame('available', $pricedEstimate['status']);
        $this->assertSame('USD', $pricedEstimate['currency']);
        $this->assertGreaterThan(0, $pricedEstimate['maximum_cost']);
        $this->assertSame(3000, $pricedEstimate['agents'][0]['maximum_total_tokens']);
    }

    public function test_preflight_compares_single_agent_and_multi_agent_token_and_cost_ceilings(): void
    {
        $user = $this->userWithApprovedModel();
        $settings = $user->llmSettings;
        $settings->model_pricing = [[
            'provider' => $settings->provider,
            'model' => $settings->model,
            'currency' => 'USD',
            'input_cost_per_million' => 1,
            'output_cost_per_million' => 2,
        ]];
        $settings->save();
        $router = app(ComplexityRouter::class);
        $estimator = app(TaskCostEstimator::class);
        $singleRouting = $router->route('Write a summary about renewable energy.');
        $multiRouting = $router->route('Research and write a summary about renewable energy with an independent review.');
        $singleEstimate = $estimator->estimate($user, 'Write a summary about renewable energy.', $singleRouting['agent_sequence']);
        $multiEstimate = $estimator->estimate($user, 'Research and write a summary about renewable energy with an independent review.', $multiRouting['agent_sequence']);

        $this->assertSame('single_agent', $singleRouting['strategy']);
        $this->assertSame('multi_agent', $multiRouting['strategy']);
        $this->assertSame(3000, $singleEstimate['estimated_tokens']);
        $this->assertSame(10000, $multiEstimate['estimated_tokens']);
        $this->assertGreaterThan($singleEstimate['maximum_cost'], $multiEstimate['maximum_cost']);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Write a summary about renewable energy.',
        ])->assertOk()
            ->assertJsonPath('routing_cost_comparison.auto.status', 'available')
            ->assertJsonPath('routing_cost_comparison.fixed.status', 'available')
            ->assertJsonPath('routing_cost_comparison.evaluation.status', 'unavailable')
            ->assertJsonPath(
                'cost_estimate.agents.0.routing_reason',
                'Cheapest policy selected the compatible approved model with the lowest estimated cost at the agent token limit using user-entered input and output rates.',
            );
    }

    public function test_token_budget_overrun_is_persisted_before_it_stops_the_run(): void
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'Budget test',
            'input' => ['prompt' => 'Test hard budget enforcement.'],
            'mode' => TASK_MODE_SANDBOX,
            'status' => TASK_STATUS_RUNNING,
        ]);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Writer',
            'input' => ['prompt' => 'Test hard budget enforcement.', 'context' => []],
            'status' => AGENT_RUN_STATUS_RUNNING,
        ]);

        try {
            app(TaskTokenBudgetService::class)->recordUsage(
                $agentRun,
                ['input_tokens' => 7, 'output_tokens' => 5, 'total_tokens' => 12],
                10,
            );
            $this->fail('The per-agent token limit should reject the over-budget usage.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The agent exceeded its configured token budget.', $exception->getMessage());
        }

        $this->assertSame(12, $agentRun->fresh()->token_usage['total_tokens']);
    }

    public function test_partial_provider_usage_is_preserved_and_missing_fields_are_estimated(): void
    {
        $usage = app(TaskTokenBudgetService::class)->responseUsage(
            [['role' => 'user', 'content' => 'A request with no exact usage metadata.']],
            new ChatResponse('A short answer.', [], ['output_tokens' => 2], 'stop'),
        );

        $this->assertGreaterThan(0, $usage['input_tokens']);
        $this->assertSame(2, $usage['output_tokens']);
        $this->assertSame($usage['input_tokens'] + 2, $usage['total_tokens']);
    }

    public function test_sandbox_uses_cheapest_user_approved_model_and_lower_token_budget(): void
    {
        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $models = array_keys($catalog->provider($providerId)['models']);
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $models[0],
            'approved_models' => [
                ['provider' => $providerId, 'model' => $models[0]],
                ['provider' => $providerId, 'model' => $models[1]],
            ],
            'agent_models' => [],
            'model_pricing' => [
                [
                    'provider' => $providerId,
                    'model' => $models[0],
                    'currency' => 'USD',
                    'input_cost_per_million' => 20,
                    'output_cost_per_million' => 20,
                ],
                [
                    'provider' => $providerId,
                    'model' => $models[1],
                    'currency' => 'USD',
                    'input_cost_per_million' => 1,
                    'output_cost_per_million' => 1,
                ],
            ],
        ]);

        $resolver = app(UserLlmProviderResolver::class);
        $this->assertSame($models[1], $resolver->selectedModel($user, 'Writer', TASK_MODE_SANDBOX)['model']);
        $this->assertSame($models[1], $resolver->selectedModel($user, 'Writer', TASK_MODE_LIVE)['model']);

        config([
            'llm.sandbox_task_token_budget' => 100,
            'llm.task_token_budget' => 1000,
        ]);
        $sandboxTask = $user->tasks()->create([
            'title' => 'Sandbox budget',
            'input' => ['prompt' => 'test'],
            'mode' => TASK_MODE_SANDBOX,
        ]);
        $sandboxRun = $sandboxTask->agentRuns()->create([
            'agent_name' => 'Writer',
            'mode' => TASK_MODE_SANDBOX,
            'input' => ['prompt' => 'test'],
        ]);
        $liveTask = $user->tasks()->create([
            'title' => 'Live budget',
            'input' => ['prompt' => 'test'],
            'mode' => TASK_MODE_LIVE,
        ]);
        $liveRun = $liveTask->agentRuns()->create([
            'agent_name' => 'Writer',
            'mode' => TASK_MODE_LIVE,
            'input' => ['prompt' => 'test'],
        ]);
        $budgetService = app(TaskTokenBudgetService::class);
        $messages = [['role' => 'user', 'content' => 'test']];

        $sandboxOutputTokens = $budgetService->maximumOutputTokens(
            $sandboxRun,
            $messages,
            1000,
            1000,
            [],
        );
        $liveOutputTokens = $budgetService->maximumOutputTokens(
            $liveRun,
            $messages,
            1000,
            1000,
            [],
        );

        $this->assertLessThan($liveOutputTokens, $sandboxOutputTokens);
    }

    public function test_model_router_obeys_brain_modes_and_never_uses_unapproved_models(): void
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
            ],
            'agent_models' => [
                'Writer' => ['provider' => $providerId, 'model' => $models[0]],
            ],
            'fallback_provider' => $providerId,
            'fallback_model' => $models[0],
            'agent_brains' => ['Writer' => AGENT_BRAIN_MODE_AUTO],
            'routing_policy' => MODEL_ROUTING_POLICY_CHEAPEST,
            'model_pricing' => [
                [
                    'provider' => $providerId,
                    'model' => $models[0],
                    'currency' => 'USD',
                    'input_cost_per_million' => 10,
                    'output_cost_per_million' => 10,
                ],
                [
                    'provider' => $providerId,
                    'model' => $models[1],
                    'currency' => 'USD',
                    'input_cost_per_million' => 1,
                    'output_cost_per_million' => 1,
                ],
            ],
        ]);

        $resolver = app(UserLlmProviderResolver::class);
        $autoSelection = $resolver->selectedModel($user, 'Writer', TASK_MODE_SANDBOX);
        $fixedSelection = $resolver->selectedModel(
            $user,
            'Writer',
            TASK_MODE_SANDBOX,
            AGENT_BRAIN_MODE_FIXED,
        );

        $this->assertSame($models[1], $autoSelection['model']);
        $this->assertSame(AGENT_BRAIN_MODE_AUTO, $autoSelection['brain_mode']);
        $this->assertSame($models[0], $fixedSelection['model']);
        $this->assertSame(AGENT_BRAIN_MODE_FIXED, $fixedSelection['brain_mode']);
        $this->assertCount(1, $autoSelection['fallback_candidates']);
        $this->assertSame($models[0], $autoSelection['fallback_candidates'][0]['model']);

        $settings = $user->llmSettings;
        $settings->agent_brains = ['Writer' => AGENT_BRAIN_MODE_CUSTOM];
        $settings->save();
        $customSelection = $resolver->selectedModel($user, 'Writer', TASK_MODE_SANDBOX);

        $this->assertSame($models[0], $customSelection['model']);
        $this->assertSame(AGENT_BRAIN_MODE_CUSTOM, $customSelection['brain_mode']);
        $this->assertStringContainsString('custom approved model', $customSelection['reason']);
    }

    public function test_balanced_routing_uses_prompt_complexity_to_raise_context_threshold(): void
    {
        $approvedModels = [
            ['provider' => ProviderCatalog::OPENAI, 'model' => 'gpt-4o-mini'],
            ['provider' => ProviderCatalog::ANTHROPIC, 'model' => 'claude-3-5-haiku-20241022'],
            ['provider' => ProviderCatalog::GEMINI, 'model' => 'gemini-2.0-flash'],
        ];
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => ProviderCatalog::OPENAI,
            'model' => 'gpt-4o-mini',
            'approved_models' => $approvedModels,
            'agent_brains' => ['Writer' => AGENT_BRAIN_MODE_AUTO],
            'routing_policy' => MODEL_ROUTING_POLICY_BALANCED,
            'model_pricing' => [
                [
                    'provider' => ProviderCatalog::OPENAI,
                    'model' => 'gpt-4o-mini',
                    'currency' => 'USD',
                    'input_cost_per_million' => 1,
                    'output_cost_per_million' => 1,
                ],
                [
                    'provider' => ProviderCatalog::ANTHROPIC,
                    'model' => 'claude-3-5-haiku-20241022',
                    'currency' => 'USD',
                    'input_cost_per_million' => 2,
                    'output_cost_per_million' => 2,
                ],
                [
                    'provider' => ProviderCatalog::GEMINI,
                    'model' => 'gemini-2.0-flash',
                    'currency' => 'USD',
                    'input_cost_per_million' => 3,
                    'output_cost_per_million' => 3,
                ],
            ],
        ]);
        $resolver = app(UserLlmProviderResolver::class);

        $simpleSelection = $resolver->selectedModel(
            $user,
            'Writer',
            TASK_MODE_SANDBOX,
            null,
            false,
            'Summarize this.',
        );
        $complexSelection = $resolver->selectedModel(
            $user,
            'Writer',
            TASK_MODE_SANDBOX,
            null,
            false,
            'Compare the alternatives and evaluate their trade-offs step-by-step.',
        );

        $this->assertSame('simple', $simpleSelection['complexity']);
        $this->assertSame(ProviderCatalog::OPENAI, $simpleSelection['provider']);
        $this->assertSame('complex', $complexSelection['complexity']);
        $this->assertSame(ProviderCatalog::ANTHROPIC, $complexSelection['provider']);
        $this->assertStringContainsString('complexity', $complexSelection['reason']);
    }

    public function test_cheapest_routing_weights_user_rates_by_estimated_input_and_output_tokens(): void
    {
        $models = [
            ['provider' => ProviderCatalog::OPENAI, 'model' => 'gpt-4o-mini'],
            ['provider' => ProviderCatalog::OPENAI, 'model' => 'gpt-4o'],
        ];
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => ProviderCatalog::OPENAI,
            'model' => 'gpt-4o-mini',
            'approved_models' => $models,
            'agent_brains' => ['Writer' => AGENT_BRAIN_MODE_AUTO],
            'routing_policy' => MODEL_ROUTING_POLICY_CHEAPEST,
            'model_pricing' => [
                [
                    'provider' => ProviderCatalog::OPENAI,
                    'model' => 'gpt-4o-mini',
                    'currency' => 'USD',
                    'input_cost_per_million' => 1000,
                    'output_cost_per_million' => 0,
                ],
                [
                    'provider' => ProviderCatalog::OPENAI,
                    'model' => 'gpt-4o',
                    'currency' => 'USD',
                    'input_cost_per_million' => 0,
                    'output_cost_per_million' => 100,
                ],
            ],
        ]);

        $selection = app(UserLlmProviderResolver::class)->selectedModel(
            $user,
            'Writer',
            TASK_MODE_SANDBOX,
            null,
            false,
            'Write a concise response.',
        );

        $this->assertSame('gpt-4o-mini', $selection['model']);
    }

    public function test_cost_estimate_includes_the_configured_fallback_attempt(): void
    {
        $models = [
            ['provider' => ProviderCatalog::OPENAI, 'model' => 'gpt-4o-mini'],
            ['provider' => ProviderCatalog::OPENAI, 'model' => 'gpt-4o'],
        ];
        $user = User::factory()->create();
        $settings = $user->llmSettings()->create([
            'provider' => ProviderCatalog::OPENAI,
            'model' => 'gpt-4o-mini',
            'approved_models' => $models,
            'fallback_provider' => ProviderCatalog::OPENAI,
            'fallback_model' => 'gpt-4o',
            'agent_brains' => ['Writer' => AGENT_BRAIN_MODE_AUTO],
            'model_pricing' => [
                [
                    'provider' => ProviderCatalog::OPENAI,
                    'model' => 'gpt-4o-mini',
                    'currency' => 'USD',
                    'input_cost_per_million' => 1,
                    'output_cost_per_million' => 2,
                ],
                [
                    'provider' => ProviderCatalog::OPENAI,
                    'model' => 'gpt-4o',
                    'currency' => 'USD',
                    'input_cost_per_million' => 3,
                    'output_cost_per_million' => 4,
                ],
            ],
        ]);
        $estimator = app(TaskCostEstimator::class);

        $estimate = $estimator->estimate($user, 'Write a concise response.', ['Writer']);

        $this->assertSame('available', $estimate['status']);
        $this->assertSame('gpt-4o', $estimate['agents'][0]['fallback_candidates'][0]['model']);
        $this->assertGreaterThan(0, $estimate['agents'][0]['fallback_maximum_cost']);
        $this->assertEqualsWithDelta(
            $estimate['agents'][0]['primary_estimated_cost'] + $estimate['agents'][0]['fallback_maximum_cost'],
            $estimate['agents'][0]['maximum_cost'],
            0.00000001,
        );

        $settings->model_pricing = array_slice($settings->model_pricing, 0, 1);
        $settings->save();
        $user->unsetRelation('llmSettings');
        $unpricedFallbackEstimate = $estimator->estimate($user, 'Write a concise response.', ['Writer']);

        $this->assertSame('unavailable', $unpricedFallbackEstimate['status']);
        $this->assertStringContainsString('fallback', $unpricedFallbackEstimate['reason']);
    }

    public function test_auto_routing_filters_models_that_do_not_support_agent_tools(): void
    {
        $providerId = ProviderCatalog::DEEPSEEK;
        $modelIds = array_keys(app(ProviderCatalog::class)->provider($providerId)['models']);
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $modelIds[1],
            'approved_models' => array_map(
                static fn (string $modelId): array => ['provider' => $providerId, 'model' => $modelId],
                $modelIds,
            ),
            'agent_models' => [
                'Research' => ['provider' => $providerId, 'model' => $modelIds[1]],
            ],
            'agent_brains' => ['Research' => AGENT_BRAIN_MODE_AUTO],
            'routing_policy' => MODEL_ROUTING_POLICY_CHEAPEST,
            'model_pricing' => [
                [
                    'provider' => $providerId,
                    'model' => $modelIds[0],
                    'currency' => 'USD',
                    'input_cost_per_million' => 1,
                    'output_cost_per_million' => 1,
                ],
                [
                    'provider' => $providerId,
                    'model' => $modelIds[1],
                    'currency' => 'USD',
                    'input_cost_per_million' => 0,
                    'output_cost_per_million' => 0,
                ],
            ],
        ]);

        $resolver = app(UserLlmProviderResolver::class);
        $selection = $resolver->selectedModel($user, 'Research', TASK_MODE_SANDBOX);

        $this->assertSame($modelIds[0], $selection['model']);

        $settings = $user->llmSettings;
        $settings->agent_brains = ['Research' => AGENT_BRAIN_MODE_CUSTOM];
        $settings->save();
        $estimate = app(TaskCostEstimator::class)->estimate($user, 'Find reliable sources.', ['Research']);

        $this->assertFalse($estimate['can_complete']);
        $this->assertStringContainsString('does not meet the capabilities', $estimate['reason']);
    }

    public function test_missing_provider_settings_are_reported_by_the_estimate_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/tasks/estimate', [
            'prompt' => 'Write a short response.',
        ])->assertOk()
            ->assertJsonPath('cost_estimate.status', 'unavailable')
            ->assertJsonPath('cost_estimate.reason', 'Save provider settings and approve a model before estimating cost.');
    }

    public function test_task_details_expose_routing_decision_reason_from_run_events(): void
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'Routing event test',
            'description' => 'Explain which model was selected.',
            'input' => ['prompt' => 'Explain which model was selected.'],
            'mode' => TASK_MODE_SANDBOX,
            'status' => TASK_STATUS_RUNNING,
        ]);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Writer',
            'input' => ['prompt' => 'Explain which model was selected.', 'context' => []],
            'mode' => TASK_MODE_SANDBOX,
            'status' => AGENT_RUN_STATUS_RUNNING,
        ]);
        $task->runEvents()->create([
            'agent_run_id' => $agentRun->id,
            'event_type' => 'agent.started',
            'mode' => TASK_MODE_SANDBOX,
            'sequence' => 1,
            'payload' => [
                'agent' => 'Writer',
                'model' => 'user-approved-model',
                'routing' => [
                    'brain_mode' => AGENT_BRAIN_MODE_AUTO,
                    'policy' => MODEL_ROUTING_POLICY_CHEAPEST,
                    'reason' => 'Selected the lowest user-entered rate.',
                ],
            ],
            'occurred_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('task.routing_events.0.payload.routing.reason', 'Selected the lowest user-entered rate.')
            ->assertJsonPath('task.routing_events.0.mode', TASK_MODE_SANDBOX);
    }

    private function userWithApprovedModel(): User
    {
        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $modelId = array_key_first($catalog->provider($providerId)['models']);
        $user = User::factory()->create();

        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $modelId,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $modelId],
            ],
            'agent_models' => [],
        ]);

        return $user;
    }
}
