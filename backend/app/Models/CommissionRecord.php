<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How one delivered order's money was split (spec 05 §5.8). Written once.
 *
 * @property int $id
 * @property int $order_id
 * @property int $vendor_gross_kobo
 * @property int $vendor_rate_bp
 * @property int $vendor_commission_kobo
 * @property int $vendor_commission_vat_kobo
 * @property int $delivery_gross_kobo
 * @property int $delivery_rate_bp
 * @property int $delivery_commission_kobo
 * @property int $delivery_commission_vat_kobo
 * @property bool $delivery_to_vendor
 * @property int $vat_collected_kobo
 * @property int $platform_revenue_kobo
 * @property int $tip_kobo
 * @property Carbon $computed_at
 */
class CommissionRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['delivery_to_vendor' => 'boolean', 'computed_at' => 'datetime'];
    }
}
