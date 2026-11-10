<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAssistantSettingsRequest;
use App\Models\UserAssistantSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $settings = $request->user()->assistantSettings;

        return response()->json([
            'settings' => [
                'persona' => $settings?->persona ?? 'friendly',
                'custom_persona' => $settings?->custom_persona,
                'voice_input_enabled' => $settings?->voice_input_enabled ?? false,
                'voice_output_enabled' => $settings?->voice_output_enabled ?? false,
                'voice_language' => $settings?->voice_language ?? 'bn-BD',
                'vision_enabled' => $settings?->vision_enabled ?? false,
                'smart_home_enabled' => false,
                'proactive_enabled' => $settings?->proactive_enabled ?? false,
                'proactive_max_suggestions' => $settings?->proactive_max_suggestions ?? 3,
                'proactive_timezone' => $settings?->proactive_timezone ?? 'UTC',
            ],
            'safety' => [
                'voice_is_authentication' => false,
                'camera_default_enabled' => false,
                'smart_home_actions_enabled' => false,
                'never_auto_devices' => ['locks', 'stoves', 'gas devices'],
            ],
        ]);
    }

    public function update(UpdateAssistantSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $settings = UserAssistantSetting::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                ...$data,
                'tenant_id' => $user->tenant_id,
                'smart_home_enabled' => false,
            ],
        );

        return response()->json([
            'settings' => [
                'persona' => $settings->persona,
                'custom_persona' => $settings->custom_persona,
                'voice_input_enabled' => $settings->voice_input_enabled,
                'voice_output_enabled' => $settings->voice_output_enabled,
                'voice_language' => $settings->voice_language ?? 'bn-BD',
                'vision_enabled' => $settings->vision_enabled,
                'smart_home_enabled' => false,
                'proactive_enabled' => $settings->proactive_enabled ?? false,
                'proactive_max_suggestions' => $settings->proactive_max_suggestions ?? 3,
                'proactive_timezone' => $settings->proactive_timezone ?? 'UTC',
            ],
            'safety' => [
                'voice_is_authentication' => false,
                'camera_default_enabled' => false,
                'smart_home_actions_enabled' => false,
                'never_auto_devices' => ['locks', 'stoves', 'gas devices'],
            ],
        ]);
    }
}
