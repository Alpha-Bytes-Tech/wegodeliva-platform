<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Http\Requests\Vendor\MenuRequest;
use App\Http\Resources\VendorMenuResource;
use App\Models\Vendor;
use App\Models\VendorMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Menu sections ("Rice dishes", "Drinks") of a store. Deleting one keeps its products. */
class MenuController extends VendorController
{
    public function index(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $this->member($request, $vendor);

        return VendorMenuResource::collection($vendor->menus()->get());
    }

    public function store(MenuRequest $request, Vendor $vendor): JsonResponse
    {
        $this->member($request, $vendor);
        $menu = $vendor->menus()->create($request->validated());

        return (new VendorMenuResource($menu))->response()->setStatusCode(201);
    }

    public function update(MenuRequest $request, Vendor $vendor, VendorMenu $menu): VendorMenuResource
    {
        $this->member($request, $vendor);
        $menu->fill($request->validated())->save();

        return new VendorMenuResource($menu);
    }

    public function destroy(Request $request, Vendor $vendor, VendorMenu $menu): Response
    {
        $this->member($request, $vendor);
        $menu->delete();

        return response()->noContent();
    }
}
