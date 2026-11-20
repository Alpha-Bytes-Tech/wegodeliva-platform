<?php

namespace App\Services\Orders;

use App\Enums\Fulfilment;
use Illuminate\Support\Carbon;

/** What the customer asked to be priced; stored with the quote and re-priced at placement. */
final readonly class QuoteInput
{
    /**
     * @param  list<array{product_id: string, quantity: int, option_ids: list<string>}>  $items
     */
    public function __construct(
        public string $vendorId,
        public array $items,
        public Fulfilment $fulfilment,
        public ?string $addressId = null,
        public ?Carbon $scheduledFor = null,
        public ?string $couponCode = null,
        public int $tipKobo = 0,
    ) {}

    /** @param  array<string, mixed>  $data  validated request data or a stored quote's input */
    public static function fromArray(array $data): self
    {
        /** @var array<int|string, array{product_id: string, quantity: int|string, option_ids?: array<int|string, string>|null}> $items */
        $items = $data['items'];

        return new self(
            vendorId: (string) $data['vendor_id'],
            items: array_map(fn (array $i) => [
                'product_id' => (string) $i['product_id'],
                'quantity' => (int) $i['quantity'],
                'option_ids' => array_values($i['option_ids'] ?? []),
            ], array_values($items)),
            fulfilment: Fulfilment::from((string) $data['fulfilment']),
            addressId: isset($data['address_id']) ? (string) $data['address_id'] : null,
            scheduledFor: isset($data['scheduled_for']) ? Carbon::parse((string) $data['scheduled_for'])->utc() : null,
            couponCode: isset($data['coupon_code']) && $data['coupon_code'] !== '' ? (string) $data['coupon_code'] : null,
            tipKobo: (int) ($data['tip_kobo'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'vendor_id' => $this->vendorId,
            'items' => $this->items,
            'fulfilment' => $this->fulfilment->value,
            'address_id' => $this->addressId,
            'scheduled_for' => $this->scheduledFor?->toIso8601String(),
            'coupon_code' => $this->couponCode,
            'tip_kobo' => $this->tipKobo,
        ];
    }
}
