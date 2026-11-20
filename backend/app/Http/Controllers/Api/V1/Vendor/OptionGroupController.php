<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Http\Requests\Vendor\OptionGroupRequest;
use App\Http\Requests\Vendor\OptionRequest;
use App\Http\Resources\OptionGroupResource;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Option groups ("Choose your drink") and their choices, shared by a store's products. */
class OptionGroupController extends VendorController
{
    public function index(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $this->member($request, $vendor);

        $collection = OptionGroupResource::collection($vendor->optionGroups()->with('options')->get());
        $collection->collection->each(fn (OptionGroupResource $r) => $r->activeOptionsOnly = false);

        return $collection;
    }

    public function store(OptionGroupRequest $request, Vendor $vendor): JsonResponse
    {
        $this->member($request, $vendor);
        $data = $request->validated();

        $group = DB::transaction(function () use ($vendor, $data): OptionGroup {
            $group = new OptionGroup(collect($data)->except('options')->all());
            $group->vendor()->associate($vendor);
            $this->assertSelection($group);
            $group->save();
            foreach ($data['options'] ?? [] as $option) {
                $group->options()->create($option);
            }

            return $group;
        });

        return $this->resource($group)->response()->setStatusCode(201);
    }

    public function update(OptionGroupRequest $request, Vendor $vendor, OptionGroup $optionGroup): OptionGroupResource
    {
        $this->member($request, $vendor);
        $optionGroup->fill($request->validated());
        $this->assertSelection($optionGroup);
        $optionGroup->save();

        return $this->resource($optionGroup);
    }

    public function destroy(Request $request, Vendor $vendor, OptionGroup $optionGroup): Response
    {
        $this->member($request, $vendor);
        $optionGroup->delete();

        return response()->noContent();
    }

    public function storeOption(OptionRequest $request, Vendor $vendor, OptionGroup $optionGroup): JsonResponse
    {
        $this->member($request, $vendor);
        $optionGroup->options()->create($request->validated());

        return $this->resource($optionGroup)->response()->setStatusCode(201);
    }

    public function updateOption(OptionRequest $request, Vendor $vendor, OptionGroup $optionGroup, Option $option): OptionGroupResource
    {
        $this->member($request, $vendor);
        $option->fill($request->validated())->save();

        return $this->resource($optionGroup);
    }

    public function destroyOption(Request $request, Vendor $vendor, OptionGroup $optionGroup, Option $option): Response
    {
        $this->member($request, $vendor);
        $option->delete();

        return response()->noContent();
    }

    private function assertSelection(OptionGroup $group): void
    {
        $group->min_select ??= 0;
        $group->max_select ??= 1;
        if ($group->min_select > $group->max_select) {
            throw ValidationException::withMessages(['max_select' => ['The maximum must be at least the minimum.']]);
        }
        if ($group->is_required && $group->min_select < 1) {
            $group->min_select = 1;
        }
    }

    private function resource(OptionGroup $group): OptionGroupResource
    {
        $resource = new OptionGroupResource($group->load('options'));
        $resource->activeOptionsOnly = false;

        return $resource;
    }
}
