<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's favourite vendor or product; exactly one of the two is set.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $vendor_id
 * @property int|null $product_id
 */
class Favourite extends Model
{
    /** Set only by the server from the signed-in user and a looked-up vendor or product. */
    protected $fillable = ['user_id', 'vendor_id', 'product_id'];

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
