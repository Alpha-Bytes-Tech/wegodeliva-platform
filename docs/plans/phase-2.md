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

## Notes from 2.3

- `backend/app/Services/Ledger`: `WalletLedger` (customers) and `EarningsLedger` (vendors and riders) are the only code that changes a balance. Each posting locks the balance row, writes ledger entries carrying the figure after the change, and is idempotent on a reference (e.g. `order:{id}:vendor`). The database refuses updates and deletes on both ledger tables.
- One earnings account per vendor or rider holds five figures: gross balance, commission due, VAT on commission due, cash owed (riders) and held for payouts. Available to withdraw = balance − commission − VAT − cash owed − held. The payout steps (hold, settle commission and cash owed, pay or release) exist and are tested; 2.7 wires them to payout requests and transfers.
- Wallets are created on first use rather than at sign-up; same effect, fewer moving parts.
- Credits are refused above the KYC tier's balance cap (`wallet_balance_cap`); debits never take a wallet below zero, except admin adjustments. Daily top-up limits are checked by the top-up flow in 2.5 (`topUpsToday`).
- Admin wallet and earnings adjustments and rider cash remittances need a client `idempotency_key`; reusing it with a different amount answers 409.
- The daily reconciliation against Paystack and Flutterwave balances (spec 06 §6.4.1) comes with 2.5, which adds the gateway clients.

## Notes from 2.4

- The cart lives in the app; the server prices it (`POST /me/cart/quote`) and stores the quote for 10 minutes, bound to the customer. `POST /me/orders` (with an `Idempotency-Key` header) re-checks and re-prices the cart on fresh data; if anything changed it answers 409 `quote_changed` with a new quote instead of placing.
- Placement is one transaction: order with price, option and address snapshots, stock taken under row locks (in product id order), coupon use recorded, and wallet payments debited. A repeated key returns the first order; the same key with a different quote answers 409.
- Statuses only change through `OrderStateMachine`, which applies the spec 04 transition table per actor (customer, vendor, rider, system, admin), locks the order and writes the append-only status history. Vendor, rider and admin actions arrive with 2.6.
- Customers can cancel in awaiting payment, scheduled and placed; stock and the coupon use are returned and whatever was paid goes back to the wallet in full (fault-based partial refunds come with 2.6).
- Scheduled orders are released by `orders:release-scheduled` every minute, at scheduled time − prep time − 10-minute buffer. **Laravel Cloud must run the scheduler** for this to work.
- Paystack and Flutterwave are switched off in config until 2.5 adds checkout and verification; until then customers pay by wallet or on delivery.
- Products that need a prescription are refused at checkout (`prescription_required`) until the prescription upload and pharmacy review flow is built.
- The handover code is stored encrypted rather than hashed, because the customer's app must show it until delivery. Riders' code attempts will be rate-limited in 2.6.
- Not yet: reorder (returns a new quote) and the order chat; both come with the customer app work.
