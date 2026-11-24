<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Storage\StorageSigner;
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
            // Waiting for the code sent to it (change of email).
            'pending_email' => $this->pending_email,
            'phone' => $this->phone,
            'phone_verified' => $this->phone_verified_at !== null,
            // ADR 0012: the app must ask the user to re-enter their phone number before going on.
            'phone_confirmation_required' => $this->phone_confirmation_required,
            'has_password' => $this->password !== null,
            'avatar_url' => app(StorageSigner::class)->readUrl($this->avatar_path),
            'status' => $this->status->value,
            'roles' => $this->roleNames(),
            'referral_code' => $this->referral_code,
            'kyc_tier' => $this->kyc_tier,
            'locale' => $this->locale,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
