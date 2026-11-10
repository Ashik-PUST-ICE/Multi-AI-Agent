<?php

namespace App\Llm;

use App\Models\User;
use InvalidArgumentException;

final class VisionCapabilityGuard
{
    public function __construct(
        private readonly UserLlmProviderResolver $providerResolver,
        private readonly ProviderCatalog $catalog,
    ) {}

    /**
     * @return array{provider: string, model: string}
     */
    public function assertSelectedRouteSupportsVision(
        User $user,
        string $agentName,
        string $mode,
        string $prompt,
        string $complexity,
    ): array {
        $selection = $this->providerResolver->selectedModel(
            $user,
            $agentName,
            $mode,
            null,
            true,
            $prompt,
            $complexity,
        );
        $models = [
            ['provider' => $selection['provider'], 'model' => $selection['model']],
            ...$selection['fallback_candidates'],
        ];

        foreach ($models as $model) {
            $capabilities = $this->catalog->model($model['provider'], $model['model']);
            if (($capabilities['vision'] ?? false) !== true) {
                throw new InvalidArgumentException(
                    'The selected model or one of its configured fallbacks is not verified for image input. Choose an approved vision-capable model and retry.',
                );
            }
        }

        return [
            'provider' => $selection['provider'],
            'model' => $selection['model'],
        ];
    }
}
