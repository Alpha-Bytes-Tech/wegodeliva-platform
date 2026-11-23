<?php

namespace App\Http\Resources;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the people fulfilling it see it (spec 10, spec 11, spec 12). Each audience sees
 * only what it needs: the vendor gets the customer's first name and area; the assigned rider
 * gets the full address and phone; admins get everything. Nobody but the customer sees the
 * handover code.
 *
 * @mixin Order
 */
class OrderOpsResource extends JsonResource
{
    /** vendor | rider | admin */
    public string $audience = 'vendor';

    public static function for(Order $order, string $audience): self
    {
        $resource = new self($order);
        $resource->audience = $audience;

        return $resource;
    }

    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        $address = $this->address_snapshot;
        $customer = $this->customer;
        $full = $this->audience !== 'vendor';

        return [
            'code' => $this->code,
            'status' => $this->status->value,
            'fulfilment' => $this->fulfilment->value,
            'payment_method' => $this->payment_method->value,
            'payment_status' => $this->payment_status->value,
            'collect' => $this->payment_method === PaymentMethod::PayOnDelivery ? Money::of($this->total_kobo) : null,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'note' => $this->note,
            'vendor' => ['id' => $this->vendor->uuid, 'name' => $this->vendor->name, 'address' => $this->vendor->address_line, 'phone' => $this->vendor->phone],
            'customer' => [
                'first_name' => strtok((string) $customer?->name, ' ') ?: null,
                'phone' => $full ? $customer?->phone : null,
            ],
            'address' => $address === null ? null : ($full ? $address : ['area' => $address['area'] ?? null, 'campus_building' => $address['campus_building'] ?? null]),
            'rider' => $this->rider === null ? null : ['id' => $this->rider->uuid, 'name' => $this->rider->name, 'phone' => $this->rider->phone],
            'items' => $this->items->map(fn (OrderItem $i) => ['name' => $i->product_name, 'quantity' => $i->quantity, 'options' => $i->options, 'line_total' => Money::of($i->line_total_kobo)])->all(),
            'subtotal' => Money::of($this->subtotal_kobo),
            'delivery_fee' => Money::of($this->delivery_fee_kobo),
            'tip' => Money::of($this->tip_kobo),
            'total' => Money::of($this->total_kobo),
            'parcel' => $this->when($this->resource->isParcel(), fn () => ParcelDetails::for($this->resource, false)),
            'prep_time_minutes' => $this->prep_time_minutes,
            'estimated_ready_at' => $this->estimated_ready_at?->toIso8601String(),
            'placed_at' => $this->placed_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            $this->mergeWhen($this->audience === 'admin', fn () => [
                'fees' => $this->fees->map(fn (OrderFee $f) => ['name' => $f->name, 'amount' => Money::of($f->amount_kobo), 'beneficiary' => $f->beneficiary->value])->all(),
                'discount' => Money::of($this->discount_kobo),
                'tax' => Money::of($this->tax_kobo),
                'cancel_reason' => $this->cancel_reason,
                'fault' => $this->fault?->value,
                'failure_reason' => $this->failure_reason,
                'handover_attempts' => $this->handover_attempts,
                'refundable' => Money::of($this->refundableKobo()),
                'history' => $this->history->map(fn (OrderStatusHistory $h) => [
                    'from' => $h->from_status?->value,
                    'to' => $h->to_status->value,
                    'actor' => $h->actor_role->value,
                    'reason' => $h->reason,
                    'at' => $h->created_at->toIso8601String(),
                ])->all(),
            ]),
        ];
    }
}
