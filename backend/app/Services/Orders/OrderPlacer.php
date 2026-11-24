<?php

namespace App\Services\Orders;

use App\Enums\ActorRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WalletKind;
use App\Exceptions\ApiException;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\OrderItem;
use App\Models\OrderQuote;
use App\Models\OrderStop;
use App\Models\Product;
use App\Models\User;
use App\Services\Ledger\WalletLedger;
use App\Services\Pricing\FeeCharge;
use App\Support\OrderCodes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Places an order from a quote (spec 04 §4.4). The cart is re-validated and re-priced on fresh
 * data; if anything changed the customer gets the new quote (409 `quote_changed`) instead of an
 * order. Otherwise one transaction creates the order with its snapshots, takes stock under row
 * locks, records the coupon use and, for wallet payments, debits the wallet. The client's
 * Idempotency-Key makes a repeated request return the first order.
 */
final class OrderPlacer
{
    public function __construct(
        private readonly QuoteBuilder $builder,
        private readonly ParcelQuoteBuilder $parcels,
        private readonly BookingQuoteBuilder $bookings,
        private readonly BookingSlots $slots,
        private readonly OrderStateMachine $machine,
        private readonly OrderLifecycle $lifecycle,
        private readonly WalletLedger $wallets,
    ) {}

    /** @return array{0: Order, 1: bool} the order, and whether this request created it */
    public function place(User $customer, OrderQuote $quote, PaymentMethod $method, ?string $note, string $idempotencyKey): array
    {
        $existing = $this->existing($customer, $quote, $idempotencyKey);
        if ($existing !== null) {
            return [$existing, false];
        }

        $this->checkCustomer($customer);
        if ($quote->expires_at->isPast()) {
            throw new ApiException('quote_expired', 422);
        }
        if ($quote->order()->exists()) {
            throw new ApiException('quote_used', 409);
        }

        $built = match ($quote->input['kind'] ?? null) {
            ParcelInput::KIND => $this->parcels->build($customer, ParcelInput::fromArray($quote->input)),
            BookingInput::KIND => $this->bookings->build($customer, BookingInput::fromArray($quote->input)),
            default => $this->builder->build($customer, QuoteInput::fromArray($quote->input)),
        };
        if ($built->fingerprint() !== $quote->fingerprint) {
            throw new ApiException('quote_changed', 409, meta: ['quote' => QuoteStore::present(QuoteStore::save($customer, $built), $built)]);
        }

        $unavailable = $built->unavailableReason($method);
        if ($unavailable !== null) {
            throw new ApiException('payment_method_unavailable', 422, fields: ['payment_method' => ['This payment method cannot be used for this order.']], meta: ['reason' => $unavailable]);
        }

        try {
            $order = DB::transaction(fn (): Order => $this->create($customer, $quote, $built, $method, $note, $idempotencyKey));
        } catch (UniqueConstraintViolationException $e) {
            // @codeCoverageIgnoreStart
            // Only reachable with two truly parallel requests (the unique keys on idempotency key
            // and quote decide the winner); a single test connection cannot reproduce it.
            return [$this->existing($customer, $quote, $idempotencyKey) ?? throw $e, false];
            // @codeCoverageIgnoreEnd
        }

        return [$this->lifecycle->autoAcceptIfEnabled($order), true];
    }

    private function existing(User $customer, OrderQuote $quote, string $key): ?Order
    {
        $order = Order::query()->where('customer_id', $customer->id)->where('idempotency_key', $key)->first();
        if ($order !== null && $order->quote_id !== $quote->id) {
            throw new ApiException('idempotency_key_reused', 409);
        }

        return $order;
    }

    private function checkCustomer(User $customer): void
    {
        if (! $customer->hasVerifiedEmail()) {
            throw new ApiException('email_not_verified', 403);
        }
        if ($customer->phone_confirmation_required) {
            throw new ApiException('phone_confirmation_required', 403);
        }
        if ($this->wallets->wallet($customer)->balance_kobo < 0) {
            throw new ApiException('wallet_negative', 422);
        }
    }

    private function create(User $customer, OrderQuote $quote, BuiltQuote $built, PaymentMethod $method, ?string $note, string $key): Order
    {
        $this->reserveStock($built);
        $price = $built->price;
        $vendor = $built->vendor;
        $scheduledFor = $built->input instanceof BookingInput ? null : $built->input->scheduledFor;
        $parcel = $built->parcel;
        $booking = $built->booking;
        // The service row is locked by reserveStock, so this check holds against parallel bookings.
        if ($booking !== null && ! $this->slots->hasRoom($booking->service, $booking->startsAt, $booking->endsAt)) {
            // @codeCoverageIgnoreStart
            // The re-priced quote already checked the slot; only a parallel request can take it in between.
            throw new ApiException('slot_unavailable', 409, ['starts_at' => ['This time is already booked. Choose another.']]);
            // @codeCoverageIgnoreEnd
        }

        $status = match (true) {
            $method->isGateway() => OrderStatus::AwaitingPayment,
            $booking !== null => OrderStatus::Requested,
            $scheduledFor !== null => OrderStatus::Scheduled,
            default => OrderStatus::Placed,
        };

        $order = new Order;
        $order->forceFill([
            'code' => $this->uniqueCode(),
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'vendor_type_id' => $vendor->vendor_type_id,
            'type' => $vendor->vendorType->slug,
            'fulfilment' => $built->fulfilment(),
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'payment_method' => $method,
            'delivery_address_id' => $built->address?->id,
            // A parcel's address is its final drop-off; every stop is kept in order_stops.
            'address_snapshot' => match (true) {
                $built->address !== null => $built->address->only(['label', 'line1', 'landmark', 'area', 'lat', 'lng', 'campus_building', 'contact_name', 'contact_phone']),
                $parcel !== null => array_intersect_key(array_slice($parcel->input->stops, -1)[0], array_flip(['line1', 'landmark', 'area', 'lat', 'lng', 'contact_name', 'contact_phone'])),
                default => null,
            },
            'distance_m' => $built->delivery->distanceM ?? $parcel?->price->routeM,
            'campus_mode' => $built->delivery?->source === 'campus',
            'scheduled_for' => $scheduledFor,
            'release_at' => $scheduledFor?->copy()->subMinutes($vendor->prep_time_minutes + (int) config('wegodeliva.orders.schedule_release_buffer_minutes')),
            'note' => $note,
            'subtotal_kobo' => $price->subtotalKobo,
            'discount_kobo' => $price->discountKobo,
            'discount_funded_by' => $price->discountFundedBy,
            'delivery_fee_kobo' => $price->deliveryFeeKobo,
            'delivery_discount_kobo' => $price->deliveryDiscountKobo,
            'service_fee_kobo' => $price->serviceFeeKobo,
            'tax_kobo' => $price->taxKobo,
            'tax_rate_bp' => $price->taxRateBp,
            'tip_kobo' => $price->tipKobo,
            'total_kobo' => $price->totalKobo,
            'coupon_id' => $price->coupon?->coupon->id,
            'package_type_id' => $parcel?->packageType->id,
            'weight_g' => $parcel?->input->weightG,
            'length_cm' => $parcel?->input->lengthCm,
            'width_cm' => $parcel?->input->widthCm,
            'height_cm' => $parcel?->input->heightCm,
            'payer' => $parcel?->input->payer,
            'units' => $booking?->units,
            'starts_at' => $booking?->startsAt,
            'ends_at' => $booking?->endsAt,
            'service_location' => $booking?->location,
            'handover_code' => OrderCodes::handoverCode(),
            'placed_at' => in_array($status, [OrderStatus::Placed, OrderStatus::Requested], true) ? now() : null,
            'estimated_delivery_at' => $built->delivery?->etaMaxMinutes === null ? null : ($scheduledFor ?? now())->copy()->addMinutes($built->delivery->etaMaxMinutes),
            'quote_id' => $quote->id,
            'idempotency_key' => $key,
        ])->save();

        foreach ($price->lines as $i => $line) {
            $item = new OrderItem;
            $item->forceFill([
                'order_id' => $order->id,
                'product_id' => $line->productId,
                'product_name' => $line->name,
                'unit_price_kobo' => $line->unitPriceKobo,
                'options' => array_map(fn ($o) => $o->toArray(), $line->options),
                'options_kobo' => $line->optionsKobo(),
                'quantity' => $line->quantity,
                'units' => $booking?->units,
                'line_total_kobo' => $line->lineTotalKobo,
                'discount_kobo' => $price->coupon?->lineDiscount($i) ?? 0,
                'vat_treatment' => $line->vatTreatment,
                'vat_rate_bp' => $price->lineTaxes[$i]->rateBp,
                'vat_kobo' => $price->lineTaxes[$i]->amountKobo,
                'stock_reserved' => $built->products[$i]->stock_qty !== null,
            ])->save();
        }

        foreach ($price->fees as $fee) {
            /** @var FeeCharge $fee */
            $row = new OrderFee;
            $row->forceFill(['order_id' => $order->id, 'fee_id' => $fee->feeId, 'name' => $fee->name, 'amount_kobo' => $fee->amountKobo, 'beneficiary' => $fee->beneficiary])->save();
        }

        if ($parcel !== null) {
            $this->createStops($order, $parcel);
        }

        if ($price->coupon !== null) {
            $redemption = new CouponRedemption;
            $redemption->forceFill([
                'coupon_id' => $price->coupon->coupon->id,
                'user_id' => $customer->id,
                'order_id' => $order->id,
                'discount_kobo' => $price->discountKobo + $price->deliveryDiscountKobo,
            ])->save();
        }

        if ($method === PaymentMethod::Wallet) {
            $this->wallets->debit($customer, $order->total_kobo, WalletKind::OrderPayment, "order:{$order->uuid}:payment", ['order_id' => $order->id, 'note' => "Order {$order->code}"]);
            $order->payment_status = PaymentStatus::Paid;
            $order->save();
        }

        $this->machine->record($order, null, $status, ActorRole::Customer, $customer);

        return $order;
    }

    /**
     * The parcel's stops in route order. The last drop-off uses the order's handover code; when
     * the package type asks for it, every other drop-off gets its own code (spec 07 §7.3).
     */
    private function createStops(Order $order, ParcelQuote $parcel): void
    {
        $last = count($parcel->input->stops) - 1;
        foreach ($parcel->input->stops as $i => $stop) {
            $code = match (true) {
                $i === 0 => null,
                $i === $last => $order->handover_code,
                default => $parcel->packageType->requires_stop_verification ? OrderCodes::handoverCode() : null,
            };

            $row = new OrderStop;
            $row->forceFill([
                'order_id' => $order->id,
                'sequence' => $i + 1,
                'kind' => $i === 0 ? 'pickup' : 'dropoff',
                'address' => ['line1' => $stop['line1'], 'landmark' => $stop['landmark'], 'area' => $stop['area'], 'lat' => $stop['lat'], 'lng' => $stop['lng']],
                'contact_name' => $stop['contact_name'],
                'contact_phone' => $stop['contact_phone'],
                'note' => $stop['note'],
                'verification_code' => $code,
            ])->save();
        }
    }

    /**
     * Takes the stock under row locks (in id order, so concurrent checkouts cannot deadlock).
     * The quote checked stock already; this check is the one that holds.
     */
    private function reserveStock(BuiltQuote $built): void
    {
        $wanted = [];
        foreach ($built->price->lines as $line) {
            $wanted[$line->productId] = ($wanted[$line->productId] ?? 0) + $line->quantity;
        }
        ksort($wanted);

        foreach ($wanted as $productId => $quantity) {
            /** @var Product $product */
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            if ($product->stock_qty === null) {
                continue;
            }
            if ($product->stock_qty < $quantity) {
                throw new ApiException('out_of_stock', 422, meta: ['product_id' => $product->uuid, 'available' => $product->stock_qty]);
            }
            $product->stock_qty -= $quantity;
            $product->save();
        }
    }

    private function uniqueCode(): string
    {
        do {
            $code = OrderCodes::orderCode();
        } while (Order::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
