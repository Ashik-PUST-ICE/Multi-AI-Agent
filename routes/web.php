<?php

use App\Http\Controllers\Api\DeviceAuthorizationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});

Route::get('/device/authorize', [DeviceAuthorizationController::class, 'showAuthorizationForm'])
    ->middleware('throttle:20,1')
    ->name('device.authorize.lookup');
