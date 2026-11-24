<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\ContentController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\FeeController;
use App\Http\Controllers\Api\V1\Admin\LedgerController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\PackageTypeController;
use App\Http\Controllers\Api\V1\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Api\V1\Admin\RiderController as AdminRiderController;
use App\Http\Controllers\Api\V1\Admin\VendorController as AdminVendorController;
use App\Http\Controllers\Api\V1\AppController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Catalogue\CatalogueController;
use App\Http\Controllers\Api\V1\Customer\AddressController;
use App\Http\Controllers\Api\V1\Customer\BookingController as CustomerBookingController;
use App\Http\Controllers\Api\V1\Customer\FavouriteController;
use App\Http\Controllers\Api\V1\Customer\OrderController;
use App\Http\Controllers\Api\V1\Customer\ParcelController as CustomerParcelController;
use App\Http\Controllers\Api\V1\Customer\PaymentController;
use App\Http\Controllers\Api\V1\Customer\ReferralController;
use App\Http\Controllers\Api\V1\Customer\ReviewController;
use App\Http\Controllers\Api\V1\Customer\WalletController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReviewListController;
use App\Http\Controllers\Api\V1\Rider\EarningsController as RiderEarningsController;
use App\Http\Controllers\Api\V1\Rider\JobController;
use App\Http\Controllers\Api\V1\Rider\PayoutController as RiderPayoutController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use App\Http\Controllers\Api\V1\SupportTicketController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\Vendor\BookingController as VendorBookingController;
use App\Http\Controllers\Api\V1\Vendor\EarningsController as VendorEarningsController;
use App\Http\Controllers\Api\V1\Vendor\MenuController;
use App\Http\Controllers\Api\V1\Vendor\OptionGroupController;
use App\Http\Controllers\Api\V1\Vendor\OrderController as VendorOrderController;
use App\Http\Controllers\Api\V1\Vendor\ParcelController as VendorParcelController;
use App\Http\Controllers\Api\V1\Vendor\PayoutController as VendorPayoutController;
use App\Http\Controllers\Api\V1\Vendor\ProductController;
use App\Http\Controllers\Api\V1\Vendor\StoreController;
use App\Http\Controllers\Api\V1\WebhookController;
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
            Route::patch('me', [AccountController::class, 'update']);
            Route::post('password', [AccountController::class, 'password'])->middleware('throttle:login');
            Route::post('email/change', [AccountController::class, 'requestEmailChange'])->middleware('throttle:otp-send');
            Route::post('email/confirm', [AccountController::class, 'confirmEmailChange'])->middleware('throttle:otp-verify');
            Route::post('account/delete-code', [AccountController::class, 'requestDeletion'])->middleware('throttle:otp-send');
            Route::delete('account', [AccountController::class, 'destroy'])->middleware('throttle:otp-verify');
        });
    });

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
        Route::post('devices', [DeviceController::class, 'store']);
        Route::delete('devices/{uuid}', [DeviceController::class, 'destroy'])->whereUuid('uuid');
        Route::post('uploads', [UploadController::class, 'store'])->middleware('throttle:uploads');
    });

    // Gateway webhooks: no sign-in; the signature is the authentication (spec 06 §6.3).
    Route::post('webhooks/{gateway}', [WebhookController::class, 'handle'])
        ->whereIn('gateway', ['paystack', 'flutterwave'])
        ->middleware('throttle:webhooks');

    // App start-up settings and content: no sign-in needed.
    Route::prefix('app')->middleware('throttle:catalogue')->group(function (): void {
        Route::get('settings', [AppController::class, 'settings']);
        Route::get('pages/{page:slug}', [AppController::class, 'page']);
        Route::get('banners', [AppController::class, 'homeBanners']);
    });

    // Public catalogue: no sign-in needed (spec 14 §14.2 "Catalogue").
    Route::prefix('catalogue')->middleware('throttle:catalogue')->group(function (): void {
        Route::get('vendor-types', [CatalogueController::class, 'vendorTypes']);
        Route::get('package-types', [CatalogueController::class, 'packageTypes']);
        Route::get('vendor-types/{vendorType:slug}/categories', [CatalogueController::class, 'categories']);
        Route::get('zones/lookup', [CatalogueController::class, 'zoneLookup']);
        Route::get('vendors', [CatalogueController::class, 'vendors']);
        Route::get('vendors/{vendor:slug}', [CatalogueController::class, 'vendor']);
        Route::get('vendors/{vendor:slug}/reviews', [ReviewListController::class, 'forCatalogue']);
        Route::get('vendors/{vendor:slug}/delivery-quote', [CatalogueController::class, 'deliveryQuote']);
        Route::get('vendors/{vendor:slug}/products', [CatalogueController::class, 'products']);
        Route::get('products/{product}', [CatalogueController::class, 'product']);
        Route::get('services/{product}/slots', [CatalogueController::class, 'slots'])->whereUuid('product');
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

        Route::middleware('throttle:checkout')->group(function (): void {
            Route::post('cart/quote', [OrderController::class, 'quote']);
            Route::post('bookings/quote', [CustomerBookingController::class, 'quote']);
            Route::post('parcels/options', [CustomerParcelController::class, 'options'])->name('me.parcels.options');
            Route::post('parcels/quote', [CustomerParcelController::class, 'quote'])->name('me.parcels.quote');
            Route::post('orders', [OrderController::class, 'place']);
            Route::post('orders/{code}/cancel', [OrderController::class, 'cancel']);
            Route::post('orders/{code}/pay', [PaymentController::class, 'payOrder']);
            Route::post('payments/{reference}/verify', [PaymentController::class, 'verify']);
        });
        Route::post('orders/{code}/reviews', [ReviewController::class, 'store']);
        Route::post('orders/{code}/confirm-completion', [CustomerBookingController::class, 'confirm']);
        Route::post('orders/{code}/report-problem', [CustomerBookingController::class, 'report']);
        Route::get('referrals', [ReferralController::class, 'mine']);
        Route::get('notifications', [NotificationController::class, 'index'])->defaults('app', 'customer');
        Route::post('notifications/read', [NotificationController::class, 'markRead'])->defaults('app', 'customer');
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{code}', [OrderController::class, 'show']);
        Route::post('orders/{code}/reorder', [OrderController::class, 'reorder']);
        Route::get('support-tickets', [SupportTicketController::class, 'index']);
        Route::post('support-tickets', [SupportTicketController::class, 'store'])->middleware('throttle:support');
        Route::get('support-tickets/{ticket}', [SupportTicketController::class, 'show'])->whereUuid('ticket');

        Route::middleware('throttle:wallet')->group(function (): void {
            Route::get('wallet', [WalletController::class, 'show']);
            Route::get('wallet/transactions', [WalletController::class, 'transactions']);
            Route::post('wallet/topups', [PaymentController::class, 'topUp']);
        });
    });

    // Rider app: own earnings and cash owed. Jobs and deliveries arrive with milestone 2.6.
    Route::prefix('rider')->middleware(['auth:sanctum', 'active', 'abilities:app:rider', 'throttle:api', 'throttle:wallet'])->group(function (): void {
        Route::get('earnings', [RiderEarningsController::class, 'show']);
        Route::get('earnings/entries', [RiderEarningsController::class, 'entries']);
    });
    Route::prefix('rider')->middleware(['auth:sanctum', 'active', 'abilities:app:rider', 'throttle:api'])->group(function (): void {
        Route::get('jobs', [JobController::class, 'index']);
        Route::get('jobs/{code}', [JobController::class, 'show']);
        Route::post('jobs/{code}/picked-up', [JobController::class, 'pickedUp']);
        Route::post('jobs/{code}/deliver', [JobController::class, 'deliver'])->middleware('throttle:handover');
        Route::post('jobs/{code}/fail', [JobController::class, 'fail']);
        Route::post('jobs/{code}/stops/{sequence}/complete', [JobController::class, 'completeStop'])->whereNumber('sequence')->middleware('throttle:handover');

        Route::get('notifications', [NotificationController::class, 'index'])->defaults('app', 'rider');
        Route::post('notifications/read', [NotificationController::class, 'markRead'])->defaults('app', 'rider');
        Route::get('documents', [DocumentController::class, 'riderIndex']);
        Route::post('documents', [DocumentController::class, 'riderSubmit']);
        Route::get('banks', [RiderPayoutController::class, 'banks']);
        Route::get('payout-accounts', [RiderPayoutController::class, 'accounts']);
        Route::post('payout-accounts', [RiderPayoutController::class, 'storeAccount'])->middleware('throttle:wallet');
        Route::delete('payout-accounts/{account}', [RiderPayoutController::class, 'destroyAccount'])->whereUuid('account');
        Route::get('payouts', [RiderPayoutController::class, 'payouts']);
        Route::post('payouts', [RiderPayoutController::class, 'requestPayoutFor'])->middleware('throttle:wallet');
    });

    // Vendor app: stores the user works at. Nested resources are scoped to their store.
    Route::prefix('vendor')->middleware(['auth:sanctum', 'active', 'abilities:app:vendor', 'throttle:api'])->group(function (): void {
        Route::get('stores', [StoreController::class, 'index']);
        Route::get('notifications', [NotificationController::class, 'index'])->defaults('app', 'vendor');
        Route::post('notifications/read', [NotificationController::class, 'markRead'])->defaults('app', 'vendor');

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

            Route::get('orders', [VendorOrderController::class, 'index']);
            Route::get('orders/{code}', [VendorOrderController::class, 'show']);
            Route::post('orders/{code}/accept', [VendorOrderController::class, 'accept']);
            Route::post('orders/{code}/reject', [VendorOrderController::class, 'reject']);
            Route::post('orders/{code}/ready', [VendorOrderController::class, 'ready']);
            Route::post('orders/{code}/handover', [VendorOrderController::class, 'handover'])->middleware('throttle:handover');

            Route::post('orders/{code}/confirm', [VendorBookingController::class, 'confirm']);
            Route::post('orders/{code}/start', [VendorBookingController::class, 'start'])->middleware('throttle:handover');
            Route::post('orders/{code}/complete', [VendorBookingController::class, 'complete']);

            Route::get('parcel-rates', [VendorParcelController::class, 'rates']);
            Route::put('parcel-rates/{packageType}', [VendorParcelController::class, 'saveRate'])->whereUuid('packageType')->withoutScopedBindings();
            Route::get('riders', [VendorParcelController::class, 'riders']);
            Route::post('orders/{code}/assign-rider', [VendorParcelController::class, 'assign']);

            Route::get('reviews', [ReviewListController::class, 'forStore']);
            Route::get('documents', [DocumentController::class, 'vendorIndex']);
            Route::post('documents', [DocumentController::class, 'vendorSubmit']);
            Route::get('banks', [VendorPayoutController::class, 'banks']);
            Route::get('payout-accounts', [VendorPayoutController::class, 'accounts']);
            Route::post('payout-accounts', [VendorPayoutController::class, 'storeAccount'])->middleware('throttle:wallet');
            Route::delete('payout-accounts/{account}', [VendorPayoutController::class, 'destroyAccount'])->whereUuid('account');
            Route::get('payouts', [VendorPayoutController::class, 'payouts']);
            Route::post('payouts', [VendorPayoutController::class, 'requestPayoutFor'])->middleware('throttle:wallet');

            Route::get('earnings', [VendorEarningsController::class, 'show']);
            Route::get('earnings/entries', [VendorEarningsController::class, 'entries']);

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
            Route::patch('vendors/{vendor}/pricing', [AdminVendorController::class, 'pricing'])->whereUuid('vendor');

            Route::get('coupons', [CouponController::class, 'index']);
            Route::post('coupons', [CouponController::class, 'store']);
            Route::get('coupons/{coupon}', [CouponController::class, 'show'])->whereUuid('coupon');
            Route::patch('coupons/{coupon}', [CouponController::class, 'update'])->whereUuid('coupon');

            Route::middleware('throttle:wallet')->group(function (): void {
                Route::get('users/{user}/wallet', [LedgerController::class, 'wallet'])->whereUuid('user');
                Route::get('users/{user}/wallet/transactions', [LedgerController::class, 'walletTransactions'])->whereUuid('user');
                Route::post('users/{user}/wallet/adjustments', [LedgerController::class, 'adjustWallet'])->whereUuid('user');
                Route::get('vendors/{vendor}/earnings', [LedgerController::class, 'vendorEarnings'])->whereUuid('vendor');
                Route::get('vendors/{vendor}/earnings/entries', [LedgerController::class, 'vendorEntries'])->whereUuid('vendor');
                Route::post('vendors/{vendor}/earnings/adjustments', [LedgerController::class, 'adjustVendor'])->whereUuid('vendor');
                Route::get('riders/{rider}/earnings', [LedgerController::class, 'riderEarnings'])->whereUuid('rider');
                Route::get('riders/{rider}/earnings/entries', [LedgerController::class, 'riderEntries'])->whereUuid('rider');
                Route::post('riders/{rider}/earnings/adjustments', [LedgerController::class, 'adjustRider'])->whereUuid('rider');
                Route::post('riders/{rider}/cash-remittances', [LedgerController::class, 'remitCash'])->whereUuid('rider');
            });

            Route::get('orders', [AdminOrderController::class, 'index']);
            Route::get('orders/{code}', [AdminOrderController::class, 'show']);
            Route::post('orders/{code}/assign-rider', [AdminOrderController::class, 'assign']);
            Route::post('orders/{code}/unassign-rider', [AdminOrderController::class, 'unassign']);
            Route::post('orders/{code}/cancel', [AdminOrderController::class, 'cancel']);
            Route::post('orders/{code}/deliver', [AdminOrderController::class, 'deliver']);
            Route::post('orders/{code}/fail', [AdminOrderController::class, 'fail']);
            Route::post('orders/{code}/resolve-dispute', [AdminOrderController::class, 'resolveDispute']);
            Route::post('orders/{code}/refunds', [AdminOrderController::class, 'refund'])->middleware('throttle:wallet');
            Route::get('payouts', [AdminPayoutController::class, 'index']);
            Route::post('payouts/{payout}/approve', [AdminPayoutController::class, 'approve'])->whereUuid('payout')->middleware('throttle:wallet');
            Route::post('payouts/{payout}/reject', [AdminPayoutController::class, 'reject'])->whereUuid('payout');
            Route::post('payouts/{payout}/sync', [AdminPayoutController::class, 'sync'])->whereUuid('payout');
            Route::post('payout-accounts/{account}/approve', [AdminPayoutController::class, 'approveAccount'])->whereUuid('account');
            Route::get('reviews', [ReviewListController::class, 'forAdmin']);
            Route::patch('reviews/{review}', [ReviewListController::class, 'setVisibility'])->whereUuid('review');
            Route::get('documents', [DocumentController::class, 'adminIndex']);
            Route::post('documents', [DocumentController::class, 'adminRequest']);
            Route::post('documents/{document}/review', [DocumentController::class, 'adminReview'])->whereUuid('document');
            Route::post('referrals/{referral}/void', [ReferralController::class, 'void'])->whereUuid('referral');
            Route::get('riders', [AdminRiderController::class, 'index']);
            Route::post('riders', [AdminRiderController::class, 'store']);

            Route::get('package-types', [PackageTypeController::class, 'index']);
            Route::post('package-types', [PackageTypeController::class, 'store']);
            Route::patch('package-types/{packageType}', [PackageTypeController::class, 'update'])->whereUuid('packageType');

            Route::get('support-tickets', [SupportTicketController::class, 'adminIndex']);
            Route::patch('support-tickets/{ticket}', [SupportTicketController::class, 'adminUpdate'])->whereUuid('ticket');
            Route::get('pages', [ContentController::class, 'pages']);
            Route::put('pages/{slug}', [ContentController::class, 'savePage']);
            Route::get('banners', [ContentController::class, 'banners']);
            Route::post('banners', [ContentController::class, 'storeBanner']);
            Route::patch('banners/{banner}', [ContentController::class, 'updateBanner'])->whereUuid('banner');

            Route::get('fees', [FeeController::class, 'index']);
            Route::post('fees', [FeeController::class, 'store']);
            Route::patch('fees/{fee}', [FeeController::class, 'update'])->whereUuid('fee');
        });
    });
});
