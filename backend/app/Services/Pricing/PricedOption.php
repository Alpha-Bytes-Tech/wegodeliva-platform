<?php

namespace App\Services\Pricing;

/** A chosen option, snapshotted with its price per unit of the line. */
final readonly class PricedOption
{
    public function __construct(
        public string $groupId,
        public string $groupName,
        public string $optionId,
        public string $name,
        public int $priceKobo,
    ) {}

    /** @return array{group_id: string, group: string, option_id: string, option: string, price_kobo: int} */
    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'group' => $this->groupName,
            'option_id' => $this->optionId,
            'option' => $this->name,
            'price_kobo' => $this->priceKobo,
        ];
    }
}
