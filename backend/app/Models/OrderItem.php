<?php

namespace App\Models;

use App\Enums\VatTreatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order line, snapshotted at placement.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property string $product_name
 * @property int $unit_price_kobo
 * @property list<array{group_id: string, group: string, option_id: string, option: string, price_kobo: int}> $options
 * @property int $options_kobo
 * @property int $quantity
 * @property int $line_total_kobo
 * @property int $discount_kobo
 * @property VatTreatment $vat_treatment
 * @property int $vat_rate_bp
 * @property int $vat_kobo
 * @property bool $stock_reserved
 */
class OrderItem extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'order_id', 'product_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'vat_treatment' => VatTreatment::class,
            'stock_reserved' => 'boolean',
            'unit_price_kobo' => 'integer',
            'options_kobo' => 'integer',
            'quantity' => 'integer',
            'line_total_kobo' => 'integer',
            'discount_kobo' => 'integer',
            'vat_rate_bp' => 'integer',
            'vat_kobo' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
