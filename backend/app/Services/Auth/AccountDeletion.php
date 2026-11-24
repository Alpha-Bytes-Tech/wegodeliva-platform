<?php

namespace App\Services\Auth;

use App\Enums\EarningsOwner;
use App\Enums\OrderStatus;
use App\Enums\UserStatus;
use App\Enums\VendorMemberRole;
use App\Models\EarningsAccount;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Deleting an account (spec 09 "Delete account", required by the app stores). Blocked while
 * the user has open orders or jobs, money in or owed to their wallet or earnings, or owns a
 * store. Otherwise personal data is removed and the account is closed; orders, payments and
 * ledgers stay for accounting, linked to an anonymous user.
 */
final class AccountDeletion
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<string> why the account cannot be deleted now (empty when it can) */
    public function blockers(User $user): array
    {
        $final = array_map(fn (OrderStatus $s) => $s->value, array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isFinal()));
        $blockers = [];

        if (Order::query()->where(fn ($q) => $q->where('customer_id', $user->id)->orWhere('rider_id', $user->id))->whereNotIn('status', $final)->exists()) {
            $blockers[] = 'open_orders';
        }
        if (Wallet::query()->where('user_id', $user->id)->where('balance_kobo', '!=', 0)->exists()) {
            $blockers[] = 'wallet_balance';
        }
        $earnings = EarningsAccount::query()->where('owner_type', EarningsOwner::Rider->value)->where('owner_id', $user->id)->first();
        if ($earnings !== null && ($earnings->balance_kobo !== 0 || $earnings->cash_owed_kobo !== 0 || $earnings->held_kobo !== 0)) {
            $blockers[] = 'rider_earnings';
        }
        if ($user->vendors()->wherePivot('role', VendorMemberRole::Owner->value)->exists()) {
            $blockers[] = 'store_owner';
        }

        return $blockers;
    }

    public function delete(User $user, ?string $reason): void
    {
        DB::transaction(function () use ($user, $reason): void {
            $user->tokens()->delete();
            $user->devices()->delete();
            $user->addresses()->delete();
            $user->favourites()->delete();
            $user->authIdentities()->delete();
            $user->vendors()->detach();

            // Unique columns get placeholders so the email and phone can be used again.
            $user->forceFill([
                'name' => 'Deleted user',
                'email' => "deleted+{$user->uuid}@deleted.invalid",
                'pending_email' => null,
                'phone' => '+0'.str_pad((string) $user->id, 14, '0', STR_PAD_LEFT),
                'password' => null,
                'avatar_path' => null,
                'status' => UserStatus::Deleted,
            ])->save();
            $user->delete();

            $this->audit->record($user, 'account.deleted', 'user', $user->uuid, after: ['reason' => $reason]);
        });
    }
}
