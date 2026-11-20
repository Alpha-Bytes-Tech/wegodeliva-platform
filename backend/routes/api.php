<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\VendorController as AdminVendorController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Catalogue\CatalogueController;
use App\Http\Controllers\Api\V1\Customer\AddressController;
use App\Http\Controllers\Api\V1\Customer\FavouriteController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use App\Http\Controllers\Api\V1\Vendor\MenuController;
use App\Http\Controllers\Api\V1\Vendor\OptionGroupController;
use App\Http\Controllers\Api\V1\Vendor\ProductController;
use App\Http\Controllers\Api\V1\Vendor\StoreController;
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
            Route::post('phone/confirm', [AuthController::class, 'confirmPhone']);
        });
    });

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
        Route::post('devices', [DeviceController::class, 'store']);
        Route::delete('devices/{uuid}', [DeviceController::class, 'destroy'])->whereUuid('uuid');
    });

    // Public catalogue: no sign-in needed (spec 14 §14.2 "Catalogue").
    Route::prefix('catalogue')->middleware('throttle:catalogue')->group(function (): void {
        Route::get('vendor-types', [CatalogueController::class, 'vendorTypes']);
        Route::get('vendor-types/{vendorType:slug}/categories', [CatalogueController::class, 'categories']);
        Route::get('zones/lookup', [CatalogueController::class, 'zoneLookup']);
        Route::get('vendors', [CatalogueController::class, 'vendors']);
        Route::get('vendors/{vendor:slug}', [CatalogueController::class, 'vendor']);
        Route::get('vendors/{vendor:slug}/products', [CatalogueController::class, 'products']);
        Route::get('products/{product}', [CatalogueController::class, 'product']);
        Route::get('search', [CatalogueController::class, 'search']);
    });

    // Customer app: own addresses and favourites.
    Route::prefix('me')->middleware(['auth:sanctum', 'active', 'abilities:app:customer', 'throttle:api'])->group(function (): void {
        Route::get('addresses', [AddressController::class, 'index']);
        Route::post('addresses', [AddressController::class, 'store']);
        Route::patch('addresses/{uuid}', [AddressController::class, 'update'])->whereUuid('uuid');
        Route::delete('addresses/{uuid}', [AddressController::class, 'destroy'])->whereUuid('uuid');

        Route::get('favourites', [FavouriteController::class, 'index']);
        Route::put('favourites/vendors/{uuid}', [FavouriteController::class, 'addVendor'])->whereUuid('uuid');
        Route::delete('favourites/vendors/{uuid}', [FavouriteController::class, 'removeVendor'])->whereUuid('uuid');
        Route::put('favourites/products/{uuid}', [FavouriteController::class, 'addProduct'])->whereUuid('uuid');
        Route::delete('favourites/products/{uuid}', [FavouriteController::class, 'removeProduct'])->whereUuid('uuid');
    });

    // Vendor app: stores the user works at. Nested resources are scoped to their store.
    Route::prefix('vendor')->middleware(['auth:sanctum', 'active', 'abilities:app:vendor', 'throttle:api'])->group(function (): void {
        Route::get('stores', [StoreController::class, 'index']);

        Route::prefix('stores/{vendor}')->whereUuid('vendor')->scopeBindings()->group(function (): void {
            Route::get('/', [StoreController::class, 'show']);
            Route::patch('/', [StoreController::class, 'update']);
            Route::put('status', [StoreController::class, 'status']);
            Route::put('hours', [StoreController::class, 'hours']);

            Route::get('menus', [MenuController::class, 'index']);
            Route::post('menus', [MenuController::class, 'store']);
            Route::patch('menus/{menu}', [MenuController::class, 'update']);
            Route::delete('menus/{menu}', [MenuController::class, 'destroy']);

            Route::get('option-groups', [OptionGroupController::class, 'index']);
            Route::post('option-groups', [OptionGroupController::class, 'store']);
            Route::patch('option-groups/{optionGroup}', [OptionGroupController::class, 'update']);
            Route::delete('option-groups/{optionGroup}', [OptionGroupController::class, 'destroy']);
            Route::post('option-groups/{optionGroup}/options', [OptionGroupController::class, 'storeOption']);
            Route::patch('option-groups/{optionGroup}/options/{option}', [OptionGroupController::class, 'updateOption']);
            Route::delete('option-groups/{optionGroup}/options/{option}', [OptionGroupController::class, 'destroyOption']);

            Route::get('products', [ProductController::class, 'index']);
            Route::post('products', [ProductController::class, 'store']);
            Route::get('products/{product}', [ProductController::class, 'show']);
            Route::patch('products/{product}', [ProductController::class, 'update']);
            Route::put('products/{product}/stock', [ProductController::class, 'stock']);
            Route::delete('products/{product}', [ProductController::class, 'destroy']);
        });
    });

    Route::prefix('admin')->group(function (): void {
        Route::post('auth/login', [AuthController::class, 'adminLogin'])->middleware('throttle:login');

        // Admin endpoints need an admin-app token, not just an admin role.
        Route::middleware(['auth:sanctum', 'active', 'abilities:app:admin', 'throttle:api'])->group(function (): void {
            Route::get('audit-log', [AuditLogController::class, 'index']);

            Route::get('vendors', [AdminVendorController::class, 'index']);
            Route::post('vendors', [AdminVendorController::class, 'store']);
            Route::get('vendors/{vendor}', [AdminVendorController::class, 'show'])->whereUuid('vendor');
            Route::post('vendors/{vendor}/approve', [AdminVendorController::class, 'approve'])->whereUuid('vendor');
            Route::post('vendors/{vendor}/suspend', [AdminVendorController::class, 'suspend'])->whereUuid('vendor');
            Route::put('vendors/{vendor}/zones', [AdminVendorController::class, 'zones'])->whereUuid('vendor');
        });
    });
});
