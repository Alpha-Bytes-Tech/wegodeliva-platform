<?php

namespace App\Services\Pricing;

/** Whether a vendor delivers to a point, and for how much (spec 05 §5.2, §5.10). */
final readonly class DeliveryQuote
{
    public function __construct(
        public bool $deliverable,
        // pickup_only | out_of_range when not deliverable.
        public ?string $reason,
        public int $feeKobo,
        // campus | zone | vendor | platform
        public ?string $source,
        public int $distanceM,
        public ?int $etaMinMinutes,
        public ?int $etaMaxMinutes,
    ) {}
}
