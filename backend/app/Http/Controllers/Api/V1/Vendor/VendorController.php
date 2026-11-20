<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Enums\VendorMemberRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * Base for vendor-app endpoints under /vendor/stores/{vendor}. A store the caller does not work
 * at answers 404, exactly like a missing one (spec 14 §14.1); owner-only actions answer 403 to
 * staff, who already know the store exists.
 */
abstract class VendorController extends Controller
{
    protected function member(Request $request, Vendor $vendor): VendorMemberRole
    {
        /** @var User $user */
        $user = $request->user();
        $role = $vendor->memberRole($user);
        if ($role === null) {
            throw (new ModelNotFoundException)->setModel(Vendor::class);
        }

        return $role;
    }

    protected function owner(Request $request, Vendor $vendor): void
    {
        if ($this->member($request, $vendor) !== VendorMemberRole::Owner) {
            throw new AuthorizationException;
        }
    }
}
