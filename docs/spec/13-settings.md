# 13. Platform settings

The old system had ~250 setting keys, many for template features or app theming. WeGoDeliva keeps the business settings below (defaults in brackets). Theming is fixed in code (brand tokens), not settings. Secrets (gateway keys, maps keys, FCM credentials, SMS keys) are **environment variables only**.

## General
- App name, support phone, support WhatsApp number, support email, emergency contact (rider app).
- Store links (Android, iOS) and **minimum app version per app** + "force update" flag (old: per-app upgrade settings).
- Social links; legal page slugs.
- Feature flags: pay on delivery, wallet top-up, referrals, scheduled orders, parcels, multi-stop parcels, prescription upload, campus mode, Google/Apple sign-in, email sign-up.

## Ordering
- Minimum / maximum order subtotal, platform-wide [₦0 / none].
- Min schedule lead time [120 min], max schedule days ahead [3].
- Statuses in which customers may cancel [awaiting_payment, awaiting_review, scheduled, placed].
- Vendor acceptance timeout [10 min] and warning [5 min].
- Prescription review timeout [60 min]; max prescription files [3] and size [5 MB].
- Online payment timeout [15 min].
- Product image limits (count [5], size [2 MB]).

## Delivery & dispatch
- Default base delivery fee, per-km fee, flat fee, max delivery distance.
- Use road distance from maps API [on].
- Rider search radius default [3 km], radius step and max [+2 km / 8 km].
- Offer time-to-live [30 s]; offers per round [1].
- Max active jobs per rider [1].
- No-rider escalation [10 min]; average speed for ETA [20 km/h].
- Location update interval / distance [10 s / 25 m]; idle interval [30 s]; auto-offline after [10 min] without updates.
- Proof of delivery: handover code on every delivery (fixed; no fallback setting).
- Rider COD cash limit [₦20,000].

## Finance
- Default vendor commission [5%]; default delivery commission [3% of the delivery fee]; commission settled at withdrawal.
- VAT rate [7.5%]; prices exclude VAT; VAT not applied to delivery or service fees; VAT on goods charged by [platform | vendor] (accountant to confirm).
- Auto-refund to wallet on cancellation [on].
- Wallet top-up min [₦100]; KYC tier limits (daily top-up, max balance) per tier [CBN figures, see 06 §6.4]; wallet withdrawals disabled (fixed).
- Payout minimum [₦1,000]; payouts on request; transfer gateway [Paystack | Flutterwave]; transfer fee borne by [platform].
- Referral reward [5% of first purchase, cap ₦5,000, referrer only]; clawback window [7 days].
- Pay on delivery max order total [₦20,000]; cancellation fee after vendor accepts [50% of items for perishable food]; rider item-loss charge cap [setting].
- Paystack enabled, Flutterwave enabled, default gateway.

## Notifications
- Editable message templates per event and audience ([08](08-notifications.md)) with placeholders.
- Admin alert recipients (emails / WhatsApp group number for ops).

## Services and chat (added 2026-11-01)
- Service booking: free-cancellation window [12 h before start], late-cancellation fee, provider acceptance timeout [2 h / 30 min if starting within 3 h], auto-confirm completion after [24 h], reminder times [24 h, 1 h].
- Chat: max message length [1,000 chars], image size [5 MB], order chats close [24 h] after the order ends, support chat hours.
- Support WhatsApp numbers [to be provided by owner].
