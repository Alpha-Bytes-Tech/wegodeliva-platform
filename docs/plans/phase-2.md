# Phase 2 plan — core marketplace backend

Agreed with the owner on 2026-11-20. Each milestone is one PR, merged only on green CI.
`docs/progress.md` records what has landed.

| # | Milestone | What it covers | Depends on |
| --- | --- | --- | --- |
| 2.1 | **Vendors and catalogue** | Vendors, staff, opening hours, delivery zones. Menus, products, options, tags and stock. Customer addresses and favourites, "do you deliver here?", vendors near me, search. Vendor and admin endpoints to manage it all. | — |
| 2.2 | **Pricing engine** | Item and option prices, delivery fee (zone / flat / per-km), coupons, service fees, VAT, totals, commission and earnings. Pure money logic at 100% coverage, including the spec 05 worked example (customer pays ₦6,137.50; shares add up to the kobo). | 2.1 |
| 2.3 | **Wallet and earnings ledgers** | Wallets, earnings accounts and rider cash balances. Every movement is a ledger entry inside a locked database transaction. | — |
| 2.4 | **Cart, checkout and order state machine** | Quote → place order with server-side recalculation and double-tap protection. Stock reservation. All order states, who may move them, full history. Pay on delivery and wallet payment. | 2.2, 2.3 |
| 2.5 | **Paystack and Flutterwave** | Start a payment, verify it (reference, amount, currency), signed webhooks, wallet top-ups, timeout jobs. Tests for tampered amounts, wrong currency and replayed transactions. | 2.4 |
| 2.6 | **Order fulfilment** | Vendor accept/reject/ready, rider picked up / delivered with handover code / failed, admin manual assignment. Commission and earnings on delivery; refunds by fault rules on cancel or fail. Scheduled jobs (vendor timeout, scheduled release, open/close). | 2.4, 2.5 |
| 2.7 | **Payouts, referrals, reviews, documents** | Payout accounts (account-name check), payout requests and admin approval through the gateway Transfers API, rider cash remittance, referral reward (5%, max ₦5,000), ratings. Vendor and rider documents for approval. | 2.6 |
| 2.8 | **Notifications** | Push (FCM), in-app inbox and email per order event, queued. | 2.6 |
| 2.9 | **Parcels and service bookings** | Parcel pricing (stops, size, extra stops) and service bookings (slots, capacity, own states). | 2.4–2.6 |

## Owner decisions (2026-11-20)

1. **Delivery distance**: straight-line (haversine) distance × 1.3 at launch, no maps API cost. Road distance from a maps API can be added later behind a setting.
2. **Gateway keys**: 2.5 is built against fake gateways in tests. For real staging tests the owner adds Paystack and Flutterwave **test** secret keys to the Laravel Cloud environment. No live keys until launch.
3. **Rider job offers**: Phase 2 has all rider actions plus admin manual assignment. Automatic offers (nearest first, countdown, widening radius) come in Phase 5 with the rider app and realtime.
4. **Chat**: backend in Phase 3 with the customer app (it needs Supabase Realtime). Service bookings stay in Phase 2 (2.9).

## Notes from 2.1

- Vendor documents moved from 2.1 to 2.7, where approval needs them.
- Zones are matched with PostGIS polygons when the `polygon` column exists (staging, CI); without PostGIS the city falls back to the nearest city centre within `catalogue.city_radius_m`.
- Image fields hold storage paths under `vendors/{vendor uuid}/…`; signed upload/download URLs for Supabase Storage come with the vendor portal work.

## Notes from 2.2

- The pricing classes live in `backend/app/Services/Pricing` and take no database input except `DeliveryQuoter` (zones). Checkout (2.4) prices lines with `LinePricer`, applies a coupon with `CouponRules`, totals with `PriceCalculator`, and on delivery (2.6) splits with `CommissionCalculator`.
- CI fails unless every line of `app/Services/Pricing` and `app/Support/Money.php` is covered (`backend/tools/coverage-gate.php`).
- Rules applied where the spec left a choice (change them if the owner disagrees):
  - Percentage service fees are charged on the subtotal before any coupon.
  - A product with no VAT treatment of its own takes its categories' treatment when they agree, otherwise standard 7.5%. The vendor can set the product's treatment.
  - Vendors on "zone" fee mode whose drop-off is outside their zones pay the platform default (₦500 base + ₦100/km, both settings). The vendor's own radius and a platform maximum of 20 km (setting) limit delivery outside its zones.
  - The vendor radius is checked on straight-line distance; fees and time estimates use straight-line × 1.3.
  - Delivery time estimate = prep time + road distance at 20 km/h, shown as a 10-minute range.
