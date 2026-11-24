<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use App\Models\ContentPage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Storage\StorageSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Content pages and banners (spec 12 "Content"). Every change is audited. */
class ContentController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly StorageSigner $signer) {}

    public function pages(): JsonResponse
    {
        Gate::authorize('manage-promotions');

        return response()->json(['data' => ContentPage::query()->orderBy('slug')->get()->map(fn (ContentPage $p) => [
            'slug' => $p->slug, 'title' => $p->title, 'body' => $p->body, 'is_published' => $p->is_published, 'updated_at' => $p->updated_at->toIso8601String(),
        ])->all()]);
    }

    /** Creates or replaces a page by slug. */
    public function savePage(Request $request, string $slug): JsonResponse
    {
        Gate::authorize('manage-promotions');
        abort_unless(preg_match('/^[a-z0-9-]{2,48}$/', $slug) === 1, 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'body' => ['required', 'string', 'max:100000'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $page = ContentPage::query()->where('slug', $slug)->first() ?? (new ContentPage)->forceFill(['slug' => $slug]);
        $created = ! $page->exists;
        $page->fill($data)->save();
        $this->audit->record($this->admin($request), $created ? 'page.created' : 'page.updated', 'content_page', $slug, after: ['title' => $page->title, 'is_published' => $page->is_published]);

        return response()->json(['data' => ['slug' => $page->slug, 'title' => $page->title, 'is_published' => $page->is_published]], $created ? 201 : 200);
    }

    public function banners(): JsonResponse
    {
        Gate::authorize('manage-promotions');
        $banners = Banner::query()->orderBy('placement')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $banners->map(fn (Banner $b) => $this->present($b))->all()]);
    }

    public function storeBanner(Request $request): JsonResponse
    {
        Gate::authorize('manage-promotions');
        $banner = Banner::query()->create($request->validate($this->rules('required')));
        $this->audit->record($this->admin($request), 'banner.created', 'banner', $banner->uuid, after: $banner->only(['placement', 'title', 'is_active']));

        return response()->json(['data' => $this->present($banner)], 201);
    }

    public function updateBanner(Request $request, Banner $banner): JsonResponse
    {
        Gate::authorize('manage-promotions');
        $before = $banner->only(['placement', 'title', 'is_active']);
        $banner->update($request->validate($this->rules('sometimes')));
        $this->audit->record($this->admin($request), 'banner.updated', 'banner', $banner->uuid, $before, $banner->only(['placement', 'title', 'is_active']));

        return response()->json(['data' => $this->present($banner)]);
    }

    /** @return array<string, mixed> */
    private function rules(string $presence): array
    {
        return [
            'placement' => [$presence, 'in:home,onboarding'],
            'title' => [$presence, 'string', 'min:2', 'max:120'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'image_path' => [$presence, 'string', 'max:255', 'regex:#^banners/[A-Za-z0-9._/-]+$#', 'not_regex:#\.\.#'],
            'link' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^/[A-Za-z0-9/_-]*$#'],
            'vendor_type_id' => ['sometimes', 'nullable', 'integer', 'exists:vendor_types,id'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Banner $banner): array
    {
        $resource = new BannerResource($banner->refresh());
        $resource->forAdmin = true;
        $resource->imageUrl = $this->signer->readUrl($banner->image_path);

        return $resource->resolve();
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->user();

        return $admin;
    }
}
