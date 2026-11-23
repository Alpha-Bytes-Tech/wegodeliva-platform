<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\Reviews;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer rates a delivered order (spec 09 "Rate order"). */
class ReviewController extends Controller
{
    public function store(Request $request, string $code, Reviews $reviews): JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $order = Order::query()->where('customer_id', $customer->id)->where('code', strtoupper($code))->first()
            ?? throw (new ModelNotFoundException)->setModel(Order::class);

        $rating = ['required', 'integer', 'between:1,5'];
        $comment = ['nullable', 'string', 'max:1000'];
        $data = $request->validate([
            'vendor' => ['nullable', 'array'],
            'vendor.rating' => array_merge(['required_with:vendor'], array_slice($rating, 1)),
            'vendor.comment' => $comment,
            'rider' => ['nullable', 'array'],
            'rider.rating' => array_merge(['required_with:rider'], array_slice($rating, 1)),
            'rider.comment' => $comment,
            'products' => ['nullable', 'array', 'max:50'],
            'products.*.product_id' => ['required', 'uuid', 'distinct'],
            'products.*.rating' => $rating,
            'products.*.comment' => $comment,
        ]);
        if (! isset($data['vendor']) && ! isset($data['rider']) && empty($data['products'])) {
            throw new ApiException('validation_failed', 400, fields: ['vendor' => ['Rate the store, the rider or a product.']]);
        }

        $created = $reviews->submit($order, $customer, $data);

        return response()->json(['data' => array_map(fn ($r) => ['id' => $r->uuid, 'subject' => $r->subject_type, 'rating' => $r->rating], $created)], 201);
    }
}
