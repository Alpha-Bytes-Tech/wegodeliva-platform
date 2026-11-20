<?php

namespace App\Services\Pricing;

use App\Enums\VatTreatment;

/**
 * One cart line with its price fixed: (unit selling price + options) × quantity (spec 05 §5.1;
 * options are charged per unit).
 */
final readonly class PricedLine
{
    public int $lineTotalKobo;

    /** @param  list<PricedOption>  $options */
    public function __construct(
        public int $productId,
        public string $productUuid,
        public string $name,
        public int $unitPriceKobo,
        public int $quantity,
        public array $options,
        public VatTreatment $vatTreatment,
    ) {
        if ($quantity < 1 || $unitPriceKobo < 0) {
            throw new \InvalidArgumentException('A line needs a positive quantity and a non-negative price.');
        }

        $this->lineTotalKobo = ($unitPriceKobo + $this->optionsKobo()) * $quantity;
    }

    /** Sum of the chosen options for one unit. */
    public function optionsKobo(): int
    {
        return array_sum(array_map(fn (PricedOption $o) => $o->priceKobo, $this->options));
    }
}
