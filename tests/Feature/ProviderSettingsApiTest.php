<?php

namespace Tests\Feature;

use App\Llm\ProviderCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_settings_are_authenticated_and_keep_api_keys_encrypted(): void
    {
        $this->getJson('/api/v1/llm/settings')->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $provider = $catalog->provider($providerId);
        $modelId = array_key_first($provider['models']);
        $this->putJson('/api/v1/llm/settings', [
            'provider' => $providerId,
            'model' => $modelId,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $modelId],
            ],
            'agent_models' => [
                'Research' => ['provider' => $providerId, 'model' => $modelId],
            ],
        ])->assertOk()
            ->assertJsonPath('settings.provider', $providerId)
            ->assertJsonPath('settings.agent_models.Research.model', $modelId);

        $apiKey = 'secret-user-api-key-123456';
        $keyResponse = $this->putJson("/api/v1/llm/keys/{$providerId}", [
            'api_key' => $apiKey,
        ])->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('masked_key', '********3456');

        $this->assertStringNotContainsString($apiKey, $keyResponse->getContent());
        $storedValue = DB::table('user_api_keys')->where('user_id', $user->id)->value('api_key');
        $this->assertNotSame($apiKey, $storedValue);
        $this->assertSame($apiKey, $user->apiKeys()->firstOrFail()->api_key);

        $this->getJson('/api/v1/llm/settings')
            ->assertOk()
            ->assertJsonPath("keys.{$providerId}.configured", true)
            ->assertJsonPath("keys.{$providerId}.masked", '********3456')
            ->assertDontSee($apiKey);
    }

    public function test_only_user_approved_models_can_be_selected_for_agent_or_fallback(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $provider = $catalog->provider($providerId);
        $models = array_keys($provider['models']);
        $approvedModel = $models[0];
        $unapprovedModel = $models[1];

        $this->putJson('/api/v1/llm/settings', [
            'provider' => $providerId,
            'model' => $approvedModel,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $approvedModel],
            ],
            'agent_models' => [
                'Writer' => ['provider' => $providerId, 'model' => $unapprovedModel],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('message', "Model [{$unapprovedModel}] is not approved for provider [{$providerId}].");

        $this->assertDatabaseMissing('user_llm_settings', ['user_id' => $user->id]);
    }

    public function test_model_pricing_is_limited_to_approved_models_and_removed_with_approval(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $models = array_keys($catalog->provider($providerId)['models']);
        $approvedModel = $models[0];
        $secondModel = $models[1];
        $pricing = static fn (string $model): array => [
            'provider' => $providerId,
            'model' => $model,
            'currency' => 'USD',
            'input_cost_per_million' => 1,
            'output_cost_per_million' => 2,
        ];

        $this->putJson('/api/v1/llm/settings', [
            'provider' => $providerId,
            'model' => $approvedModel,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $approvedModel],
                ['provider' => $providerId, 'model' => $secondModel],
            ],
            'model_pricing' => [$pricing($approvedModel), $pricing($secondModel)],
        ])->assertOk()->assertJsonCount(2, 'settings.model_pricing');

        $this->putJson('/api/v1/llm/settings', [
            'provider' => $providerId,
            'model' => $approvedModel,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $approvedModel],
            ],
        ])->assertOk()
            ->assertJsonCount(1, 'settings.model_pricing')
            ->assertJsonPath('settings.model_pricing.0.model', $approvedModel);

        $this->putJson('/api/v1/llm/settings', [
            'provider' => $providerId,
            'model' => $approvedModel,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $approvedModel],
            ],
            'model_pricing' => [$pricing($secondModel)],
        ])->assertUnprocessable();
    }

    public function test_private_custom_base_urls_are_rejected_by_default(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $providerId = null;

        foreach (app(ProviderCatalog::class)->all() as $candidateProviderId => $provider) {
            if ($provider['custom_base_url']) {
                $providerId = $candidateProviderId;
                break;
            }
        }

        $this->assertNotNull($providerId);

        $this->putJson("/api/v1/llm/keys/{$providerId}", [
            'base_url' => 'http://127.0.0.1:11434/v1',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Custom base URLs must use HTTPS unless private endpoints are explicitly authorized.');
    }
}
