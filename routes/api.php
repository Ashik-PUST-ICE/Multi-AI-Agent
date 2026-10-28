<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'locale' => app()->getLocale(),
        'message' => __('app.health'),
    ]);
});

Route::get('/v1/info', function () {
    return response()->json([
        'name' => config('app.name'),
        'version' => '0.1.0',
        'providers' => array_keys(config('llm.providers', [])),
    ]);
});

Route::get('/v1/user', function (Request $request) {
    return response()->json([
        'authenticated' => (bool) $request->user(),
        'user' => $request->user()?->only(['id', 'name', 'email']),
    ]);
});
