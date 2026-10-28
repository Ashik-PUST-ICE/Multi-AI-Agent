<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Services\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authenticationService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->authenticationService->register($request->validated());
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->authenticationService->login($request->validated());
    }

    public function logout(Request $request): JsonResponse
    {
        return $this->authenticationService->logout($request);
    }
}
