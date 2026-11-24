<?php

namespace App\Services\Storage;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Vendor;

/**
 * Signs the images of a page of stores and products in one storage call, so the resources'
 * per-item lookups come from the cache instead of one call each.
 */
final class StorageImages
{
    public function __construct(private readonly StorageSigner $signer) {}

    /**
     * @param  iterable<Vendor>  $vendors
     * @param  iterable<Product>  $products  with `images` loaded
     */
    public function warm(iterable $vendors = [], iterable $products = []): void
    {
        $paths = [];
        foreach ($vendors as $vendor) {
            $paths[] = $vendor->logo_path;
            $paths[] = $vendor->cover_path;
        }
        foreach ($products as $product) {
            if ($product->relationLoaded('images')) {
                foreach ($product->images as $image) {
                    /** @var ProductImage $image */
                    $paths[] = $image->path;
                }
            }
        }
        $this->signer->readUrls(array_values(array_filter($paths)));
    }
}
