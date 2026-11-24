<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientApp;
use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use App\Models\ContentPage;
use App\Models\VendorType;
use App\Services\Storage\StorageSigner;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What the apps read at start-up and for content (spec 09 "Splash", "Onboarding", "Home",
 * "Legal / content pages"). Public, no sign-in.
 */
class AppController extends Controller
{
    public function __construct(private readonly StorageSigner $signer) {}

    public function settings(Request $request): JsonResponse
    {
        $app = (string) $request->validate(['app' => ['required', 'in:customer,vendor,rider']])['app'];
        $w = config('wegodeliva');

        return response()->json(['data' => [
            'app' => [
                'min_version' => $w['app']['versions'][$app]['min'],
                'latest_version' => $w['app']['versions'][$app]['latest'],
                'store_urls' => $w['app']['store_urls'],
            ],
            'features' => [
                'parcels' => (bool) $w['parcels']['enabled'],
                'multi_stop_parcels' => (bool) $w['parcels']['multi_stop'],
                'service_bookings' => (bool) $w['bookings']['enabled'],
                'referrals' => (bool) $w['referrals']['enabled'],
                'wallet_topups' => (bool) $w['payments']['topups_enabled'],
                'campus_mode' => (bool) $w['pricing']['campus_mode'],
                'google_sign_in' => $w['social']['google_client_ids'] !== [],
                'apple_sign_in' => $w['social']['apple_client_ids'] !== [],
            ],
            'payment_methods' => [
                'paystack' => (bool) $w['payments']['paystack'],
                'flutterwave' => (bool) $w['payments']['flutterwave'],
                'wallet' => (bool) $w['payments']['wallet'],
                'pay_on_delivery' => (bool) $w['payments']['pay_on_delivery'],
            ],
            'limits' => [
                'pay_on_delivery_max' => Money::of((int) $w['payments']['pay_on_delivery_max_kobo']),
                'max_tip' => Money::of((int) $w['pricing']['max_tip_kobo']),
                'parcel_max_stops' => (int) $w['parcels']['max_stops'],
                'ticket_photos' => (int) $w['app']['max_ticket_photos'],
            ],
            'support' => $w['app']['support'],
            'support_categories' => $w['app']['support_categories'],
            'pages' => ContentPage::query()->where('is_published', true)->orderBy('slug')->get(['slug', 'title'])->map(fn (ContentPage $p) => ['slug' => $p->slug, 'title' => $p->title])->all(),
            'onboarding' => $app === ClientApp::Customer->value
                ? $this->banners(Banner::query()->live()->where('placement', 'onboarding')->get())->resolve()
                : [],
        ]]);
    }

    public function page(ContentPage $page): JsonResponse
    {
        abort_unless($page->is_published, 404);

        return response()->json(['data' => ['slug' => $page->slug, 'title' => $page->title, 'body' => $page->body, 'updated_at' => $page->updated_at->toIso8601String()]]);
    }

    /** Home carousel, optionally for one vendor type's landing page. */
    public function homeBanners(Request $request): AnonymousResourceCollection
    {
        $type = $request->validate(['vendor_type' => ['nullable', 'string', 'max:32']])['vendor_type'] ?? null;
        $typeId = $type === null ? null : (VendorType::query()->where('slug', $type)->value('id') ?? 0);

        return $this->banners(Banner::query()->live()->where('placement', 'home')
            ->when($typeId !== null, fn (Builder $q) => $q->where('vendor_type_id', $typeId), fn (Builder $q) => $q->whereNull('vendor_type_id'))
            ->get());
    }

    /** @param  Collection<int, Banner>  $banners */
    private function banners(Collection $banners): AnonymousResourceCollection
    {
        $urls = $this->signer->readUrls($banners->pluck('image_path')->all());

        return BannerResource::collection($banners->map(function (Banner $b) use ($urls) {
            $resource = new BannerResource($b);
            $resource->imageUrl = $urls[$b->image_path] ?? null;

            return $resource;
        }));
    }
}
