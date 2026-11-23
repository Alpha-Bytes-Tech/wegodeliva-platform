<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Orders\Reviews;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Reading reviews: a store's visible reviews in the catalogue and for its own staff, and every
 * review for admins, who can hide or show one (spec 09 vendor page, spec 10, spec 12).
 */
class ReviewListController extends Controller
{
    public function forCatalogue(Vendor $vendor): AnonymousResourceCollection
    {
        abort_unless($vendor->status->value === 'active', 404);

        return $this->vendorReviews($vendor);
    }

    public function forStore(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        // Same membership rule as every vendor endpoint: outsiders get 404.
        abort_if($vendor->memberRole($user) === null, 404);

        return $this->vendorReviews($vendor);
    }

    public function forAdmin(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage-vendors');
        $validated = $request->validate(['subject' => ['nullable', 'in:vendor,rider,product'], 'hidden' => ['nullable', 'boolean']]);

        $page = Review::query()->with('author')
            ->when($validated['subject'] ?? null, fn (Builder $q, string $s) => $q->where('subject_type', $s))
            ->when(isset($validated['hidden']), fn (Builder $q) => $q->where('is_visible', ! (bool) $validated['hidden']))
            ->orderByDesc('id')
            ->cursorPaginate(25);

        return ReviewResource::collection($page->through(fn (Review $r) => tap(new ReviewResource($r), fn (ReviewResource $res) => $res->forAdmin = true)));
    }

    public function setVisibility(Request $request, Review $review, Reviews $reviews): ReviewResource
    {
        Gate::authorize('manage-vendors');
        $visible = (bool) $request->validate(['is_visible' => ['required', 'boolean']])['is_visible'];
        $resource = new ReviewResource($reviews->setVisible($review, $visible)->load('author'));
        $resource->forAdmin = true;

        return $resource;
    }

    private function vendorReviews(Vendor $vendor): AnonymousResourceCollection
    {
        return ReviewResource::collection(
            Review::query()->where('subject_type', 'vendor')->where('subject_id', $vendor->id)->where('is_visible', true)->with('author')->orderByDesc('id')->cursorPaginate(20)
        );
    }
}
