<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as its customer sees it (spec 09 "Order details"), including the handover code
 * until the order is finished.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $cancellable = in_array($this->status->value, (array) config('wegodeliva.orders.customer_cancellable'), true);

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'payment_method' => $this->payment_method->value,
            'fulfilment' => $this->fulfilment->value,
            'type' => $this->type,
            'vendor' => $this->whenLoaded('vendor', fn () => ['id' => $this->vendor->uuid, 'name' => $this->vendor->name, 'slug' => $this->vendor->slug, 'phone' => $this->vendor->phone]),
            'address' => $this->address_snapshot,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'note' => $this->note,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (OrderItem $i) => [
                'name' => $i->product_name,
                'quantity' => $i->quantity,
                'unit_price' => Money::of($i->unit_price_kobo),
                'options' => $i->options,
                'line_total' => Money::of($i->line_total_kobo),
            ])->all()),
            'fees' => $this->whenLoaded('fees', fn () => $this->fees->map(fn (OrderFee $f) => ['name' => $f->name, 'amount' => Money::of($f->amount_kobo)])->all()),
            'subtotal' => Money::of($this->subtotal_kobo),
            'discount' => Money::of($this->discount_kobo),
            'delivery_fee' => Money::of($this->delivery_fee_kobo),
            'delivery_discount' => Money::of($this->delivery_discount_kobo),
            'service_fee' => Money::of($this->service_fee_kobo),
            'tax' => Money::of($this->tax_kobo),
            'tip' => Money::of($this->tip_kobo),
            'total' => Money::of($this->total_kobo),
            'handover_code' => $this->status->isFinal() || $this->status === OrderStatus::AwaitingPayment ? null : $this->handover_code,
            'parcel' => $this->when($this->resource->isParcel(), fn () => ParcelDetails::for($this->resource, true)),
            'can_cancel' => $cancellable && ! $this->status->isFinal(),
            'cancel_reason' => $this->cancel_reason,
            'estimated_ready_at' => $this->estimated_ready_at?->toIso8601String(),
            'estimated_delivery_at' => $this->estimated_delivery_at?->toIso8601String(),
            'placed_at' => $this->placed_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'timeline' => $this->whenLoaded('history', fn () => $this->history->map(fn (OrderStatusHistory $h) => [
                'status' => $h->to_status->value,
                'at' => $h->created_at->toIso8601String(),
            ])->all()),
        ];
    }
}
