<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $product_id
 * @property string $path Object path in the private product-images bucket
 * @property int $sort_order
 */
class ProductImage extends Model
{
    protected $fillable = ['path', 'sort_order'];
}
