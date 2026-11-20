<?php

namespace App\Services\Pricing;

use App\Enums\VatTreatment;
use App\Exceptions\ApiException;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;

/**
 * Prices one cart line and checks the chosen options (spec 05 §5.1): every option must belong
 * to an active group of the product, and each group's selection must respect its limits.
 * Expects `optionGroups.options` and `categories` to be loaded on the product.
 */
final class LinePricer
{
    /**
     * @param  list<string>  $optionIds  option UUIDs chosen by the customer
     * @param  string  $field  request field to attach errors to, e.g. "items.0.options"
     */
    public function price(Product $product, int $quantity, array $optionIds, string $field = 'options'): PricedLine
    {
        if (count($optionIds) !== count(array_unique($optionIds))) {
            $this->fail($field, 'The same option was chosen twice.');
        }

        /** @var array<string, array{0: OptionGroup, 1: Option}> $available */
        $available = [];
        foreach ($product->optionGroups as $group) {
            if (! $group->is_active) {
                continue;
            }
            foreach ($group->options as $option) {
                if ($option->is_active) {
                    $available[$option->uuid] = [$group, $option];
                }
            }
        }

        $chosen = [];
        $perGroup = [];
        foreach ($optionIds as $id) {
            if (! isset($available[$id])) {
                $this->fail($field, 'One of the options is not available for this product.');
            }
            [$group, $option] = $available[$id];
            $perGroup[$group->id] = ($perGroup[$group->id] ?? 0) + 1;
            $chosen[] = new PricedOption($group->uuid, $group->name, $option->uuid, $option->name, $option->price_kobo);
        }

        foreach ($product->optionGroups as $group) {
            if (! $group->is_active) {
                continue;
            }
            $count = $perGroup[$group->id] ?? 0;
            $min = max($group->min_select, $group->is_required ? 1 : 0);
            if ($count < $min) {
                $this->fail($field, "Choose at least {$min} from \"{$group->name}\".");
            }
            if ($count > $group->max_select) {
                $this->fail($field, "Choose at most {$group->max_select} from \"{$group->name}\".");
            }
        }

        return new PricedLine(
            productId: $product->id,
            productUuid: $product->uuid,
            name: $product->name,
            unitPriceKobo: $product->sellingPriceKobo(),
            quantity: $quantity,
            options: $chosen,
            vatTreatment: self::vatTreatment($product),
        );
    }

    /**
     * The product's own treatment, else its categories' if they agree, else standard. Mixed
     * category treatments fall back to standard; the vendor can set the product's treatment.
     */
    public static function vatTreatment(Product $product): VatTreatment
    {
        if ($product->vat_treatment !== null) {
            return $product->vat_treatment;
        }

        $fromCategories = $product->categories
            ->map(fn (Category $c) => $c->vat_treatment)
            ->filter()
            ->unique()
            ->values();

        return $fromCategories->count() === 1
            ? VatTreatment::from((string) $fromCategories->first())
            : VatTreatment::Standard;
    }

    private function fail(string $field, string $message): never
    {
        throw new ApiException('invalid_options', 422, fields: [$field => [$message]]);
    }
}
