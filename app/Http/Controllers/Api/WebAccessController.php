<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserWebAccessSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebAccessController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $settings = $request->user()->webAccessSettings;

        return response()->json([
            'web_access' => [
                'search_providers' => [
                    ['id' => 'brave', 'name' => 'Brave Search'],
                    ['id' => 'tavily', 'name' => 'Tavily'],
                ],
                'search_provider' => $settings?->search_provider,
                'has_search_api_key' => filled($settings?->search_api_key),
                'allowed_domains' => $settings?->allowed_domains ?? [],
                'denied_domains' => $settings?->denied_domains ?? [],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search_provider' => ['sometimes', 'nullable', Rule::in(['brave', 'tavily'])],
            'search_api_key' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'allowed_domains' => ['sometimes', 'array', 'max:100'],
            'allowed_domains.*' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'denied_domains' => ['sometimes', 'array', 'max:100'],
            'denied_domains.*' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
        ]);

        $settings = UserWebAccessSetting::query()->firstOrNew(['user_id' => $request->user()->id]);
        if (! $settings->exists) {
            $settings->tenant_id = $request->user()->tenant_id;
        }

        if (array_key_exists('search_provider', $data)) {
            $settings->search_provider = $data['search_provider'];
        }
        if (isset($data['search_api_key']) && $data['search_api_key'] !== '') {
            $settings->search_api_key = $data['search_api_key'];
        }
        foreach (['allowed_domains', 'denied_domains'] as $field) {
            if (array_key_exists($field, $data)) {
                $settings->{$field} = array_values(array_unique(array_map(
                    static fn (string $domain): string => strtolower(rtrim($domain, '.')),
                    $data[$field],
                )));
            }
        }

        if ($settings->search_provider !== null && blank($settings->search_api_key)) {
            throw ValidationException::withMessages([
                'search_api_key' => 'A search API key is required to enable the selected search provider.',
            ]);
        }

        $settings->save();

        return response()->json([
            'web_access' => [
                'search_provider' => $settings->search_provider,
                'has_search_api_key' => filled($settings->search_api_key),
                'allowed_domains' => $settings->allowed_domains ?? [],
                'denied_domains' => $settings->denied_domains ?? [],
            ],
        ]);
    }

    public function deleteSearchKey(Request $request): JsonResponse
    {
        $settings = UserWebAccessSetting::query()->where('user_id', $request->user()->id)->first();
        if ($settings !== null) {
            $settings->update([
                'search_provider' => null,
                'search_api_key' => null,
            ]);
        }

        return response()->json(['search_provider' => null, 'has_search_api_key' => false]);
    }
}
