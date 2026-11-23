<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Services\Orders\Referrals;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The customer's referral code, share link and referrals (spec 06 §6.8); admin voiding. */
class ReferralController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $referrals = Referral::query()->where('referrer_user_id', $user->id)->with('referred')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => [
            'code' => $user->referral_code,
            'share_url' => config('wegodeliva.referrals.share_url').$user->referral_code,
            'reward_percent' => (int) config('wegodeliva.referrals.reward_bp') / 100,
            'max_reward' => Money::of((int) config('wegodeliva.referrals.max_reward_kobo')),
            'earned' => Money::of((int) $referrals->where('status', 'rewarded')->sum('reward_kobo')),
            'referrals' => $referrals->map(fn (Referral $r) => [
                'name' => strtok((string) $r->referred?->name, ' ') ?: null,
                'status' => $r->status,
                'reward' => Money::of($r->reward_kobo),
                'joined_at' => $r->created_at->toIso8601String(),
            ])->all(),
        ]]);
    }

    public function void(Request $request, Referral $referral, Referrals $referrals): JsonResponse
    {
        Gate::authorize('manage-money');
        /** @var User $admin */
        $admin = $request->user();
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $referral = $referrals->void($referral, $admin, $reason);

        return response()->json(['data' => ['id' => $referral->uuid, 'status' => $referral->status, 'void_reason' => $referral->void_reason]]);
    }
}
