<?php

namespace Tests\Support;

use App\Enums\ClientApp;
use App\Models\Address;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Testing\TestResponse;

/** Builders for checkout tests: a store open 24/7 near Yaba, a customer and an address nearby. */
final class Checkout
{
    /** @param  array<string, mixed>  $attributes */
    public static function vendor(array $attributes = []): Vendor
    {
        return Vendor::factory()->create(array_merge([
            'delivery_fee_mode' => 'flat',
            'delivery_fee_kobo' => 50000,
            'accepts_pickup' => true,
            'allows_scheduled_orders' => true,
            'prep_time_minutes' => 20,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    public static function product(Vendor $vendor, array $attributes = []): Product
    {
        return Product::factory()->for($vendor)->create(array_merge(['price_kobo' => 150000, 'stock_qty' => null], $attributes));
    }

    /** About 1.1 km from the factory vendor location. */
    public static function address(User $user, float $lat = 6.525, float $lng = 3.385): Address
    {
        return $user->addresses()->create(['label' => 'Home', 'line1' => '1 Test Street', 'landmark' => 'Opposite the mosque', 'lat' => $lat, 'lng' => $lng]);
    }

    public static function token(User $user): string
    {
        return tokenFor($user, ClientApp::Customer);
    }

    /**
     * @param  list<array{0: Product, 1?: int, 2?: list<string>}>  $lines
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function cart(Vendor $vendor, array $lines, ?Address $address, array $extra = []): array
    {
        return array_merge([
            'vendor_id' => $vendor->uuid,
            'items' => array_map(fn (array $l) => ['product_id' => $l[0]->uuid, 'quantity' => $l[1] ?? 1, 'option_ids' => $l[2] ?? []], $lines),
            'fulfilment' => $address === null ? 'pickup' : 'delivery',
            'address_id' => $address?->uuid,
        ], $extra);
    }

    /** @param  array<string, mixed>  $cart */
    public static function quote(string $token, array $cart): TestResponse
    {
        return test()->withToken($token)->postJson('/api/v1/me/cart/quote', $cart);
    }

    public static function place(string $token, string $quoteId, string $method = 'pay_on_delivery', string $key = 'tap-1', array $extra = []): TestResponse
    {
        return test()->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/me/orders', ['quote_id' => $quoteId, 'payment_method' => $method] + $extra);
    }
}
