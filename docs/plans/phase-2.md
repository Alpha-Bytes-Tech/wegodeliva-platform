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
| 2.7 | **Payouts, referrals, reviews, documents** (two PRs: 2.7a payouts, 2.7b the rest) | Payout accounts (account-name check), payout requests and admin approval through the gateway Transfers API, rider cash remittance, referral reward (5%, max ₦5,000), ratings. Vendor and rider documents for approval. | 2.6 |
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

## Notes from 2.5

- `backend/app/Services/Payments`: `PaystackClient` and `FlutterwaveClient` behind one `GatewayClient` interface; `PaymentProcessor` starts checkouts, verifies and applies results; `Reconciliation` runs the daily check. Flutterwave works in naira, converted to and from kobo in its client only (string arithmetic, no float drift).
- A payment is accepted only when the gateway, asked with our secret key, reports success **and** our reference, the exact amount in kobo and NGN all match, **and** the gateway transaction has not already paid for something else (a unique key backs this). Anything else fails the payment, leaves the order unpaid, logs a critical error and writes `payment.rejected` to the audit log.
- The app's "verify" and the webhook both only trigger that server check; applying a result is idempotent under a row lock, so they can race. Webhooks need a valid signature (`x-paystack-signature` HMAC-SHA512, Flutterwave `verif-hash`), are stored once (replays ignored) and processed on the queue. Transfer webhooks are stored now and handled with payouts (2.7).
- `payments:expire` (every minute): gateway orders unpaid after 15 minutes get one last check, then are cancelled (stock and coupon returned); pending top-ups are failed. Money that arrives later is never lost: top-ups are still credited, and payments for cancelled or already-paid orders go to the customer's wallet (`payment.refunded_late` in the audit log).
- Wallet top-ups: Idempotency-Key, minimum ₦100, tier daily limit and balance cap, counting top-ups still in flight. Once paid, a top-up is always credited even if the wallet filled up meanwhile.
- `payments:reconcile` (daily, 06:00 Lagos) checks that gateway balances cover positive wallet balances plus vendor and rider earnings net of commission, VAT and cash owed; a shortfall or an unreadable gateway is logged as critical and audited. Exact ledger-to-gateway equality needs the platform's own revenue ledger and in-flight settlements, which come with payouts (2.7).
- Not yet: gateway IP allow-listing for webhooks (SHOULD), admin alerts beyond logs and the audit log (notifications, 2.8).

## Notes from 2.6

- Vendor app: inbox tabs (new, preparing, ready, out, done, scheduled), accept with a prep time (5–180 min), reject or cancel with a reason (full refund, vendor at fault), mark ready, and hand pickup orders over against the customer's code. Vendors see the customer's first name and delivery area only; orders still awaiting payment stay hidden.
- Riders: an admin turns an existing account into an approved rider (`POST /admin/riders`) and assigns them to delivery orders before pickup. Vendor-owned riders can only take their vendor's orders, and riders are limited to `max_active_orders` (default 1). Offers, matching, online status and live tracking come with the rider app (Phase 5); so does vendors assigning their own riders.
- Delivery always needs the customer's 4-digit code: three wrong codes lock it (423) until support completes the delivery as an audited admin override. Code tries are also rate-limited per user (10 a minute). Pay-on-delivery handovers must say whether the customer paid by cash or transfer.
- At delivery, once per order and in the same transaction (`DeliverySettlement`):
  - pay-on-delivery becomes paid, and the cash is owed by whoever collected it: the rider, or the vendor for pickups and its own riders;
  - commission is recorded in `commission_records`;
  - the vendor's earnings and the deliverer's earnings are credited.
  
  The referral reward on a first delivered order comes with referrals (2.7).
- Failed deliveries need a reason, a photo under `orders/{order uuid}/` and a call to the customer. Refunds for them are an admin decision.
- Admin refunds go to the wallet: full or partial, never more than was paid and not refunded, with optional reversals of the vendor's or rider's earnings when the fault is theirs, keyed by an idempotency key and audited.
- Admin cancellation takes a fault. A cancellation fee applies only when the customer is at fault; it is kept from the refund and paid to the vendor less commission.
- `orders:monitor` (every minute):
  - alerts when a store has not accepted after 5 minutes and cancels with a full refund at 10;
  - alerts at 10 and 30 minutes for delivery orders without a rider;
  - flags orders still open after 24 hours.
  
  Alerts go to the log and the audit log until notifications arrive (2.8). Store open/close needs no job: the open state is computed from hours when read.
- Not yet: zone-scoped access for zone admins (admin panel, Phase 6), pharmacy prescription review, signed upload URLs for delivery photos (rider app).

## Notes from 2.7a (payouts)

- Bank accounts: the vendor owner or rider picks a bank from the gateway's list (cached a day) and gives a 10-digit number. The name comes from the bank via the gateway's account-resolve API, never from the user. The account is `verified` if a word of three or more letters matches the rider's name, or the vendor's name or one of its owners' names; otherwise it is `needs_review` until an admin approves it. Numbers are shown masked.
- Payout flow (`App\Services\Payments\Payouts`), with an Idempotency-Key on the request:
  - **Request:** at least ₦1,000 (setting), to a verified account; the amount is held against the earnings.
  - **Admin approves:** commission, its VAT and (riders) cash owed are settled from the balance, then the transfer is sent through the payout gateway (setting, default Paystack). If the gateway is down the payout stays approved, and approving again retries.
  - **Result:** the transfer's result is always asked from the gateway — prompted by its signed webhook, an admin "sync", or `payouts:sync` every 5 minutes for payouts processing over 5 minutes. Success pays out the hold; failure releases it (the settled commission stays settled) and is audited.
  - **Admin rejects:** releases the hold.
- Transfer fees are borne by WeGoDeliva (spec 13 default); deducting them from the payout is not built.
- Not yet: approving only from settled gateway funds (needs settlement tracking), auto-approval under a threshold (SHOULD), and payout statements (SHOULD).
- **Owner, before real payouts:**
  - Paystack Transfers must be enabled on the business account, and the transfer OTP turned off so the API can send transfers.
  - Flutterwave Transfers must be approved on its account.
  - The transfer balance must be funded.

## Notes from 2.7b (referrals, reviews, documents)

- **Referrals** (`App\Services\Orders\Referrals`):
  - Signing up with a code records a pending referral. An unknown code no longer blocks sign-up (spec 06 §6.8); the response says `referral_applied: false`. The Phase 1 test that expected a rejection was changed accordingly.
  - The reward is paid at the referred customer's first delivered order, inside the delivery transaction: 5% of subtotal − discount, capped at ₦5,000 (settings), credited to the referrer's wallet.
  - A full refund of that order within 7 days takes the reward back (only as much as the referrer's wallet holds) and the next delivered order counts. Admins can void a referral, which also takes back a paid reward. Clawbacks and voids are audited.
  - One referral per new account; self-referral is ignored. Device-based limits come with the apps.
- **Reviews** (`App\Services\Orders\Reviews`): after delivery, within 30 days (setting), the customer rates the vendor, the rider and the order's products, once each. Averages and counts on vendors, products and riders are recomputed from visible reviews. Admins can hide or show a review. Stores' visible reviews are public in the catalogue and shown to their staff, with the author's first name only.
- **Documents** (`App\Services\Documents`): store owners and riders submit documents of set kinds, with paths that must sit in their own folder (`vendors/{uuid}/documents/…`, `riders/{uuid}/documents/…`). Admins can ask for a kind, then approve or reject with a note; resubmitting replaces a request or a rejection. Signed URLs for viewing come with the admin panel; approval does not yet gate going live (admins approve vendors and riders separately).

## Notes from 2.8 (notifications)

- Built on Laravel notifications. Each message goes to the user's inbox for one app (customer, vendor or rider), to push on that user's devices for that app, and, where spec 08 asks, to email.
- Messages are queued and sent only after the database change commits, so a rolled-back change notifies nobody. **The queue worker must run in production.**
- Domain events (`App\Events`) are raised where things happen (status changes, rider assignment, refunds, wallet credits, failed payments, payouts, document requests, order alerts). `App\Listeners\NotificationRouter` decides who hears what:

  | Who | What they hear about | Email too |
  | --- | --- | --- |
  | Customer | Order placed or scheduled, accepted with prep time, ready for pickup, rider assigned, on the way, delivered, cancelled (with reason), failed; still finding a rider; failed payment; refund; wallet top-up; referral reward | Receipt on delivery; refund |
  | Store (owner and staff) | New order (urgent, Android channel `new_orders`, iOS time-sensitive), acceptance reminder, rider coming, picked up, delivered, cancelled by someone else, failed, still finding a rider | — |
  | Store owners only | Payouts, document requests | Yes |
  | Rider | Job assigned (urgent), order ready, job cancelled, delivered | — |
  | Rider | Payouts, document requests | Yes |

- Push uses FCM HTTP v1 with a service account from `FIREBASE_CREDENTIALS_BASE64` (base64 JSON, environment only). Messages go to each device token, never shared topics, and carry only ids and short text. Tokens FCM reports as unregistered or invalid are deleted. Without credentials push is off and the inbox and email still work.
- Inbox endpoints: `GET /me/notifications`, `/vendor/notifications`, `/rider/notifications` (unread count, filter), and `POST …/notifications/read` (some or all).
- Texts live in `lang/en/notifications.php` with placeholders; admin-editable templates come with the admin panel (Phase 6).
- Not yet:
  - Admin alerts beyond the log and audit log (email or realtime dashboard).
  - The email fallback when a store's new-order push fails (SHOULD).
  - Broadcasts and muting marketing messages.
  - Chat, booking and KYC messages (with those features).
  - Realtime updates (Phase 3/5).
  - "Rider nearby" (Phase 5).
- **Owner:** create a Firebase service-account key (Firebase console → Project settings → Service accounts → Generate new private key), base64-encode the JSON, and add it to Laravel Cloud as `FIREBASE_CREDENTIALS_BASE64`. Emails need the Brevo sender set up.

## Notes from 2.9a (parcels)

- **Owner decisions (2026-11-23):**
  - Only the operator's own (vendor-owned) riders can take its parcel jobs. The operator earns the whole parcel price less the vendor commission (5% by default) and pays its riders off-platform.
  - There is no VAT on parcels: the price is treated like a delivery charge. The accountant can revisit this before launch.
- **Data:** `package_types` (admin-managed, seeded: Document, Small parcel, Medium box, Large box), `parcel_rates` (one per operator and package type), `order_stops` (pickup, then drop-offs, with address snapshot, contact and an encrypted code) and parcel columns on `orders` (package type, weight, dimensions, payer). Parcel orders have type `parcels` (the vendor type slug); their `address_snapshot` is the final drop-off.
- **Price** (`ParcelPricer`, spec 05 §5.3): base + distance rate (per km of route, or flat) + size rate (per kg, or flat) + extra-stop fee × (stops − 2). The route is the sum of the legs, each straight-line × 1.3. The price is the order subtotal and total. There is no delivery fee, VAT, coupon or tip; commission applies to the subtotal, and delivery earnings go to the operator.
- **Coverage:** a stop is covered if it lies in one of the operator's zones or within its delivery radius (and the platform maximum), the same fallback as deliveries until zone polygons are drawn.
- **Flow:**
  1. `POST /me/parcels/options` lists the operators covering every stop, cheapest first. Operators that cannot take the parcel show why: weight or dimensions needed, closed, or schedule not possible.
  2. `POST /me/parcels/quote` with `operator_id` saves a quote.
  3. `POST /me/orders` places it like any order.

  Scheduling uses the rate's `max_advance_booking_days` and the usual minimum lead time. A recipient who pays can only pay on delivery.
- **Codes and delivery:**
  - The last drop-off uses the order's handover code.
  - When the package type requires stop verification, each earlier drop-off gets its own code; the sender's app shows the codes until each stop is done.
  - The rider completes earlier drop-offs with `POST /rider/jobs/{code}/stops/{n}/complete` (three wrong codes lock the stop), then delivers at the last stop with the handover code. Delivery is refused while earlier drop-offs are open (`stops_incomplete`).
  - Picking up marks the pickup stop done.
- **Operators:**
  - `GET/PUT /vendor/stores/{id}/parcel-rates[/{packageType}]` (prices are owner-only).
  - `GET /vendor/stores/{id}/riders`.
  - `POST /vendor/stores/{id}/orders/{code}/assign-rider` lets any store assign its own riders, audited in the status history as the vendor.
- **Admin:** `GET/POST/PATCH /admin/package-types` (audited). Public: `GET /catalogue/package-types`.
- **Flags:** `PARCELS_ENABLED`, `PARCELS_MULTI_STOP`, `PARCELS_MAX_STOPS` (default 5 stops including pickup).
- **Not yet:** the rate's `auto_dispatch` flag is stored but has no effect until automatic rider offers (Phase 5). Proof-of-delivery photos per stop and parcel insurance are not built.

## Notes from 2.9b (service bookings)

- **Owner decisions (2026-11-23):**
  - Cancelling a confirmed booking less than 12 hours before the start costs **50% of the service price** (after any discount). The provider keeps it, less commission. Earlier cancellations, and cancelling a request the provider has not yet confirmed, are free.
  - The provider is paid **on confirmation**. Earnings are credited when the customer confirms the job is done, or automatically 24 hours after the provider marks it complete if no problem was reported. A reported problem freezes the money until support decides.
- **Model:** services are products of a services store with a pricing unit, and bookings are orders of type `services` (ADR 0014). Option groups are shared, and each option can be charged per unit or once per booking.
- **Price** (spec 05 §5.9): price × units + options + call-out fee (flat or per km, only at the customer's address, treated like a delivery fee: 3% delivery commission, to the provider). Coupons, store fees and VAT apply as for goods.
- **Slots:**
  - A booking must fall inside the provider's hours. Services by the hour or of fixed length must also end before closing.
  - Fewer bookings than the service's capacity may overlap the slot. Bookings waiting for payment or for the provider hold their slot.
  - The lead time is 1 hour and bookings can be made up to 30 days ahead.
  - `GET /catalogue/services/{id}/slots?date=&units=` lists free start times every 30 minutes.
- **Flow:**
  1. The customer gets a quote with `POST /me/bookings/quote` and places it with `POST /me/orders`. The booking becomes `requested`, or `awaiting_payment` first for card payment.
  2. The provider confirms with `POST /vendor/stores/{id}/orders/{code}/confirm` or declines with `/reject` (full refund).
  3. The provider starts the job with the customer's start code (the handover code) via `/start`. This is possible from an hour before the slot; three wrong codes lock it.
  4. The provider marks the job done with `/complete`, entering how the customer paid if the booking is pay on completion.
  5. The customer confirms with `POST /me/orders/{code}/confirm-completion`, or reports a problem with `/report-problem` within 24 hours.
  6. Support resolves a problem with `POST /admin/orders/{code}/resolve-dispute`: `release` pays the provider; `refund` cancels and refunds in full. For a partial refund, support releases and then uses the refund endpoint. Resolutions are audited.
- **`bookings:monitor`** (every minute):
  - Requests not confirmed within 2 hours (30 minutes when the booking starts within 3 hours) are cancelled with a full refund, at the provider's fault.
  - Reminders go out 24 hours and 1 hour before the start.
  - Completed bookings are auto-confirmed after 24 hours.
  - Bookings are left out of the generic "stale order" alert.
- **Notifications:** request sent and new booking (loud push to the store), confirmed, reminder, started, done ("confirm or report a problem"), problem reported (to the store).
- **Provider's view:** the provider sees the customer's address and phone, like a rider. The vendor inbox has `requested`, `booked` and `booking_done` tabs.
- **Pay on completion** collects the cash at confirmation. A dispute on a pay-on-completion booking has no platform refund (the cash went to the provider); support handles it with a release plus an earnings adjustment.
- **Not yet:** calendar view, recurring bookings, in-app chat (Phase 3), laundry pickup and return with a rider (LATER in spec 01).
