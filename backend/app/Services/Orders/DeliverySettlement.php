<?php

namespace App\Services\Orders;

use App\Enums\EarningsOwner;
use App\Enums\Fulfilment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RiderType;
use App\Models\CommissionRecord;
use App\Models\Order;
use App\Models\OrderFee;
use App\Services\Ledger\EarningsLedger;
use App\Services\Pricing\CommissionCalculator;
use App\Services\Pricing\FeeCharge;
use App\Services\Pricing\PriceBreakdown;

/**
 * What happens once, at delivery (spec 04 §4.7, spec 05 §5.8): pay-on-delivery orders become
 * paid and the cash is owed by whoever collected it; commission is computed and recorded; the
 * vendor's and the deliverer's earnings are credited. Call it inside the transaction that marks
 * the order delivered. The referral reward for a first delivered order is paid here too.
 */
final class DeliverySettlement
{
    public function __construct(
        private readonly CommissionCalculator $commission,
        private readonly EarningsLedger $earnings,
        private readonly Referrals $referrals,
    ) {}

    public function settle(Order $order): void
    {
        if (CommissionRecord::query()->where('order_id', $order->id)->exists()) {
            return;
        }
        $order->loadMissing('vendor', 'fees', 'rider.riderProfile');

        // Delivery earnings go to the vendor for pickups, vendor-owned riders, or no rider at all.
        $profile = $order->rider?->riderProfile;
        $toVendor = $order->fulfilment === Fulfilment::Pickup || $order->rider === null || $profile?->rider_type === RiderType::VendorOwned;
        $vendorAccount = $this->earnings->account(EarningsOwner::Vendor, $order->vendor_id);
        $deliveryAccount = $toVendor ? $vendorAccount : $this->earnings->account(EarningsOwner::Rider, (int) $order->rider_id);

        if ($order->payment_method === PaymentMethod::PayOnDelivery) {
            $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();
            $this->earnings->collectCash($deliveryAccount, $order->total_kobo, "order:{$order->uuid}:cash", $order->id);
        }

        $split = $this->commission->split($this->breakdown($order), $order->vendor->commission_rate_bp, null, $toVendor);

        $record = new CommissionRecord;
        $record->forceFill([
            'order_id' => $order->id,
            'vendor_gross_kobo' => $split->vendor->grossKobo,
            'vendor_rate_bp' => $split->vendor->commissionRateBp,
            'vendor_commission_kobo' => $split->vendor->commissionKobo,
            'vendor_commission_vat_kobo' => $split->vendor->commissionVatKobo,
            'delivery_gross_kobo' => $split->delivery->grossKobo,
            'delivery_rate_bp' => $split->delivery->commissionRateBp,
            'delivery_commission_kobo' => $split->delivery->commissionKobo,
            'delivery_commission_vat_kobo' => $split->delivery->commissionVatKobo,
            'delivery_to_vendor' => $toVendor,
            'vat_collected_kobo' => $split->vatCollectedForVendorKobo,
            'platform_revenue_kobo' => $split->platformRevenueKobo,
            'tip_kobo' => $order->tip_kobo,
            'computed_at' => now(),
        ])->save();

        $this->earnings->recordOrderEarning($vendorAccount, $split->vendor, "order:{$order->uuid}:vendor", $order->id);
        if ($split->delivery->grossKobo > 0) {
            $this->earnings->recordOrderEarning($deliveryAccount, $split->delivery, "order:{$order->uuid}:delivery", $order->id);
        }

        $this->referrals->rewardIfFirstPurchase($order);
    }

    /** The order's stored amounts in the shape the commission calculator takes. */
    private function breakdown(Order $order): PriceBreakdown
    {
        $fees = $order->fees->map(fn (OrderFee $f) => new FeeCharge((int) $f->fee_id, $f->name, $f->amount_kobo, $f->beneficiary))->all();

        return new PriceBreakdown(
            lines: [],
            lineTaxes: [],
            subtotalKobo: $order->subtotal_kobo,
            discountKobo: $order->discount_kobo,
            discountFundedBy: $order->discount_funded_by,
            deliveryFeeKobo: $order->delivery_fee_kobo,
            deliveryDiscountKobo: $order->delivery_discount_kobo,
            fees: array_values($fees),
            serviceFeeKobo: $order->service_fee_kobo,
            taxKobo: $order->tax_kobo,
            taxRateBp: $order->tax_rate_bp,
            tipKobo: $order->tip_kobo,
            totalKobo: $order->total_kobo,
            coupon: null,
        );
    }
}
