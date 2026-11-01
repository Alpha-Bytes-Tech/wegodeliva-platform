<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'phone' => $this->phone,
            'phone_verified' => $this->phone_verified_at !== null,
            'status' => $this->status->value,
            'roles' => $this->roleNames(),
            'referral_code' => $this->referral_code,
            'kyc_tier' => $this->kyc_tier,
            'locale' => $this->locale,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
