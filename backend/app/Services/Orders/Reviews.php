<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\RiderProfile;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Ratings after delivery (spec 03 §3.8, spec 09 "Rate order"): the customer rates the vendor,
 * the rider and the products of a delivered order, once each, within the review window. The
 * rating average and count on each subject are recomputed from visible reviews.
 */
final class Reviews
{
    /**
     * @param  array{vendor?: array{rating: int, comment?: string|null}, rider?: array{rating: int, comment?: string|null}, products?: list<array{product_id: string, rating: int, comment?: string|null}>}  $input
     * @return list<Review>
     */
    public function submit(Order $order, User $author, array $input): array
    {
        if ($order->status !== OrderStatus::Delivered || $order->delivered_at === null) {
            throw new ApiException('order_not_reviewable', 409, meta: ['status' => $order->status->value]);
        }
        if ($order->delivered_at->lt(now()->subDays((int) config('wegodeliva.reviews.window_days')))) {
            throw new ApiException('review_window_closed', 422);
        }

        $subjects = [];
        if (isset($input['vendor'])) {
            $subjects[] = ['vendor', $order->vendor_id, $input['vendor']];
        }
        if (isset($input['rider'])) {
            if ($order->rider_id === null) {
                throw new ApiException('validation_failed', 400, fields: ['rider' => ['This order had no rider.']]);
            }
            $subjects[] = ['rider', $order->rider_id, $input['rider']];
        }
        $productIds = $order->items()->whereNotNull('product_id')->pluck('product_id', 'product_id');
        foreach ($input['products'] ?? [] as $i => $product) {
            $id = Product::withTrashed()->where('uuid', $product['product_id'])->value('id');
            if ($id === null || ! $productIds->has($id)) {
                throw new ApiException('validation_failed', 400, fields: ["products.{$i}.product_id" => ['This product was not in the order.']]);
            }
            $subjects[] = ['product', (int) $id, $product];
        }

        return DB::transaction(function () use ($order, $author, $subjects): array {
            $reviews = [];
            foreach ($subjects as [$type, $id, $data]) {
                if (Review::query()->where('order_id', $order->id)->where('subject_type', $type)->where('subject_id', $id)->exists()) {
                    throw new ApiException('already_reviewed', 409, meta: ['subject' => $type]);
                }
                $review = new Review;
                $review->forceFill([
                    'order_id' => $order->id,
                    'author_user_id' => $author->id,
                    'subject_type' => $type,
                    'subject_id' => $id,
                    'rating' => $data['rating'],
                    'comment' => $data['comment'] ?? null,
                ])->save();
                $reviews[] = $review;
                $this->recompute($type, $id);
            }

            return $reviews;
        });
    }

    public function setVisible(Review $review, bool $visible): Review
    {
        $review->forceFill(['is_visible' => $visible])->save();
        $this->recompute($review->subject_type, $review->subject_id);

        return $review;
    }

    private function recompute(string $type, int $id): void
    {
        $stats = Review::query()->where('subject_type', $type)->where('subject_id', $id)->where('is_visible', true)
            ->selectRaw('COUNT(*) AS n, COALESCE(AVG(rating), 0) AS avg')->first();
        $values = ['rating_count' => (int) $stats?->getAttribute('n'), 'rating_avg' => round((float) $stats?->getAttribute('avg'), 2)];

        match ($type) {
            'vendor' => Vendor::withTrashed()->whereKey($id)->update($values),
            'product' => Product::withTrashed()->whereKey($id)->update($values),
            default => RiderProfile::query()->where('user_id', $id)->update($values),
        };
    }
}
