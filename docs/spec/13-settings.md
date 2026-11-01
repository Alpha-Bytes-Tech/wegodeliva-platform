# 13. Platform settings

The old system had ~250 setting keys, many for template features or app theming. WeGoDeliva keeps the business settings below (defaults in brackets). Theming is fixed in code (brand tokens), not settings. Secrets (gateway keys, maps keys, FCM credentials, SMS keys) are **environment variables only**.

## General
- App name, support phone, support WhatsApp number, support email, emergency contact (rider app).
- Store links (Android, iOS) and **minimum app version per app** + "force update" flag (old: per-app upgrade settings).
- Social links; legal page slugs.
- Feature flags: pay on delivery, wallet top-up, referrals, scheduled orders, parcels, multi-stop parcels, prescription upload, proof-of-delivery photo/signature fallback, campus mode, Google/Apple sign-in, email sign-up.

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
- Proof of delivery method [code], fallbacks allowed [photo].
- Rider COD cash limit [₦20,000].

## Finance
- Default vendor commission [owner to decide, e.g. 15%], default rider share of delivery fee [e.g. 80%].
- Default tax rate [owner/accountant to decide]; prices VAT-inclusive [owner to decide].
- Auto-refund to wallet on cancellation [on].
- Wallet top-up min [₦100] / max [owner to decide].
- Payout minimum [₦1,000]; payout schedule (on request / weekly).
- Referral reward amount for referrer [owner], for new user [owner].
- Paystack enabled, Flutterwave enabled, default gateway.

## Notifications
- Editable message templates per event and audience ([08](08-notifications.md)) with placeholders.
- Admin alert recipients (emails / WhatsApp group number for ops).
