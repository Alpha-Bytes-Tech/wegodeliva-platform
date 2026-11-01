<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 (docs/spec/14-api-behaviour.md). JSON only; errors use the §14.1 format.
 */
Route::prefix('v1')->group(function (): void {
    Route::get('health', [HealthController::class, 'live']);
    Route::get('health/ready', [HealthController::class, 'ready']);

    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:otp-send');
        Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:otp-verify');
        Route::post('social/{provider}', SocialAuthController::class)->middleware('throttle:social');

        Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
        Route::post('devices', [DeviceController::class, 'store']);
        Route::delete('devices/{uuid}', [DeviceController::class, 'destroy'])->whereUuid('uuid');
    });

    Route::prefix('admin')->group(function (): void {
        Route::post('auth/login', [AuthController::class, 'adminLogin'])->middleware('throttle:login');

        // Admin endpoints need an admin-app token, not just an admin role.
        Route::middleware(['auth:sanctum', 'active', 'abilities:app:admin', 'throttle:api'])->group(function (): void {
            Route::get('audit-log', [AuditLogController::class, 'index']);
        });
    });
});
