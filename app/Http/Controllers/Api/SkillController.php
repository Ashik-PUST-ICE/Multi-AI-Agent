<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SkillController extends Controller
{
    public function index(): JsonResponse
    {
        $skills = array_map(static fn (array $skill): array => [
            'name' => $skill['name'],
            'version' => $skill['version'],
            'description' => $skill['description'],
            'owner' => 'built_in',
            'status' => 'active',
            'minimum_trust_level' => $skill['minimum_trust_level'],
            'required_tools' => $skill['required_tools'],
            'model_capabilities' => $skill['model_capabilities'],
            'scripts_enabled' => false,
        ], config('skills.built_in', []));

        return response()->json([
            'skills' => $skills,
            'maximum_matches_per_task' => (int) config('skills.maximum_matches', 2),
            'external_installation_enabled' => false,
        ]);
    }
}
