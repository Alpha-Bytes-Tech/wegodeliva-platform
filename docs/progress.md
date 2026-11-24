# Progress

Read this at the start of every session; update it at the end.

## Current phase: 2 — Core marketplace backend · in progress (plan: [plans/phase-2.md](plans/phase-2.md))

Last updated: 2026-11-23

## Done

- 2026-11-01 — Repository initialised (`main`), connected to `github.com/Alpha-Bytes-Tech/wegodeliva-platform` (private), first commit with `CLAUDE.md` pushed.
- 2026-11-01 — Legacy code read once (backend, customer, vendor and rider apps in `../legacy/`) for Phase 0 only. From Phase 1 on, build from `docs/spec/` only.
- 2026-11-01 — Functional specification written in `docs/spec/`.
- 2026-11-01 — Owner confirmed the out-of-scope list, then amended it: **services** and **in-app chat** are in scope at launch.
- 2026-11-01 — Owner approved the rest of the spec and answered open questions 3–27. Applied to the spec: nine launch campuses across five cities (multi-city), all six verticals, 5% vendor / 3% delivery commission settled at withdrawal, VAT rules, vendor-funded coupons, per-additional-stop parcel fee, refund fault policy, CBN-tier closed-loop wallet, pay on delivery up to ₦20,000, payouts via gateway transfers, 5%/₦5,000 referral, handover code on every delivery, three rider types, email + phone required, Google + Apple sign-in, existing Firebase project kept, and old data treated as test data.
- 2026-11-01 — CLAUDE.md updated: clean-room check excludes CLAUDE.md (owner approved); product description, Phase 0/3/7 wording aligned with the owner's decisions.
- 2026-11-01 — Service categories (14) added to the spec as seed data. OTP switched from SMS to **email** (owner decision): no SMS provider at launch, phones collected but not verified yet, parcel stop codes shared from the sender's app.
- 2026-11-01 — Second round of answers (R1–R8) applied. App IDs are kept (Play App Signing is on; upload key reset needed). Vendors charge and remit their own VAT; WeGoDeliva charges VAT on its commission; default VAT table per category researched. Wallet licensing researched; owner decided to keep cash top-ups with funds held at Paystack/Flutterwave (app shows balances only) and confirmed both gateways can hold earnings until withdrawal. Daily ledger-vs-gateway reconciliation added. Riders are test accounts too.

- 2026-11-01 — Phase 1 built in a cloud session (branch `phase-1/foundations`, one PR into `main`, not merged):
  - **CI fixed**: the workflow on `main` was invalid (`hashFiles()` in job-level `if`), so no job had ever run. Fixed, plus `pull-requests: read` for paths-filter.
  - **backend/**: Laravel 12 API on Postgres (PostGIS-ready). Users/roles/OTP/devices/auth identities/append-only audit log/cities/zones/vendor types/categories. Auth: customer sign-up (role always customer), email OTP send/verify, password login with app role hint, admin login, password reset by OTP, Google/Apple ID-token verification (JWKS) behind an interface, logout, me; app-scoped Sanctum tokens; spec 14 error envelope; rate limits per spec 13; device registration; health/readiness; audit-log endpoint. Seeders: vendor types, 14 service categories, 6 cities + 9 campus zones, super admin from env. 122 Pest tests, 96% coverage, Pint, Larastan level 6, composer audit clean. Docker Compose with PostGIS + Mailpit.
  - **web/**: Next.js 16 admin shell: login through a route handler that keeps the token in an httpOnly cookie, protected layout with spec 12 sections, dashboard placeholder, brand tokens + dark mode. ESLint/Prettier, Vitest (12 tests), Playwright smoke test, build.
  - **mobile/**: Melos 8 pub workspace: `core`, `ui`, `auth` packages; customer/vendor/rider shells (splash → email → code → home), dev/staging/prod flavours, Android IDs kept, target SDK 36. 44 unit/widget tests, analyze clean.
  - Owner decision applied: riders and vendor owners/staff get the `customer` role automatically when they sign in to the customer app (spec 02 §2.4 updated; admin-only accounts excluded).
  - ADRs 0009 (roles table), 0010 (app-scoped tokens, cookie session for admin), 0011 (pub workspace + shared auth package).
- 2026-11-16 — Code-review fixes on the Phase 1 PR (all 9 findings, each with regression tests): account pre-hijacking via email code and social sign-in (ADR 0012: first email proof drops password + tokens, phone re-confirmed via `POST /auth/phone/confirm`), account-wide login limit (10/15 min), OTP send limits keyed per email+IP with a 20/h email ceiling (spec 13 updated), OTP mail queued only after the challenge commits, real app version in `X-App-Version`, offline session state with retry instead of a false sign-out, global 401/`account_suspended` sign-out, admin cookie expiry from the token's `expires_at`. CI Flutter pinned to 3.47.9 (3.48 deprecates `material.dart`).
- 2026-11-16 — Owner approved; PR #5 "Phase 1: foundations" merged into `main` (merge commit `ad09b82`) with all CI jobs green. Phase 1 is "done" once staging is reachable (CLAUDE.md §5).
- 2026-11-16 — Vercel project `wegodeliva-platform` set to build `web/` (Next.js, Node 22). Supabase staging project `WeGoDeliva` (eu-central-1, free plan) prepared: no Data API access to Laravel's tables, RLS on every new table, PostGIS in `extensions` (PR #7, `supabase/migrations/`).
- 2026-11-18 — **Staging is up**: API on Laravel Cloud (`wegodeliva-platform-production-p9jmsm.laravel.cloud`, health ready), admin panel on Vercel (`wegodeliva-platform.vercel.app`, `API_BASE_URL` set), migrations and seeders run, super admin signed in. Runbook: `docs/runbooks/staging.md`. Added `php artisan admin:reset-password` and pinned the audit-log trigger function's search_path (Supabase advisor).
- 2026-11-20 — Phase 2 started; plan and owner decisions in `docs/plans/phase-2.md` (straight-line distance ×1.3, gateway test keys from the owner, automatic rider offers in Phase 5, chat in Phase 3).
- 2026-11-20 — **Milestone 2.1, vendors and catalogue** (branch `claude/lucid-maxwell-86gh1d`): vendors with members (owner/staff), opening hours in Africa/Lagos time with manual open/busy/closed override, delivery zones with fee overrides; menus, products (kobo prices, promo price, stock, VAT treatment), images, tags, option groups and options; customer addresses (landmark, campus building, server-side zone/city) and favourites. Public catalogue API (vendor types, categories, zone lookup, vendors near a point with distance and deliverability, vendor, products, search; rate-limited). Vendor API (stores, settings, status, hours, menus, option groups, products, stock). Admin API (create vendor with owner account, approve, suspend with reason, zones; all audited). 214 Pest tests, Pint, PHPStan, composer audit clean.
- 2026-11-20 — Milestone 2.1 merged (PR #11).
- 2026-11-20 — **Milestone 2.2, pricing engine**: line prices with per-unit options and option rules; coupons (percent/fixed, caps, scope by vendor type/vendors/products, dates, usage limits, first order, vendor- or platform-funded, delivery-fee coupons) spread over lines; VAT per line on the discounted base for VAT-registered vendors; service fees; totals; commission and earnings split with VAT on commission. The spec 05 worked example (₦6,137.50) is reproduced to the kobo, and shares are tested to add up to the total. Delivery fee rule (campus → vendor zone → vendor flat/per-km → platform default; straight-line × 1.3) and public `GET /catalogue/vendors/{slug}/delivery-quote`. Admin endpoints for coupons, service fees and per-vendor commission and fees (audited). CI now requires 100% coverage of the pricing code. Rules chosen where the spec was open are listed in `docs/plans/phase-2.md`.
- 2026-11-20 — Milestone 2.2 merged (PR #12).
- 2026-11-20 — **Milestone 2.3, wallet and earnings ledgers**: append-only `wallet_transactions` and `earnings_entries` (database triggers refuse edits and deletes); balances change only through `WalletLedger` / `EarningsLedger` under row locks with idempotent references. Customer wallet with CBN-style tier limits (balance cap enforced, daily top-up count ready for 2.5); vendor and rider earnings with commission and VAT accrual, cash owed, payout hold/settle/pay/release, reversals that can leave a debt. Endpoints: `GET /me/wallet` (+ transactions), `GET /vendor/stores/{id}/earnings` (+ entries), `GET /rider/earnings` (+ entries), admin wallet/earnings views, adjustments and rider cash remittances (audited, idempotency key, rate-limited). Coverage gate extended to the ledger code.
- 2026-11-20 — Milestone 2.3 merged (PR #13).
- 2026-11-20 — **Milestone 2.4, checkout and order state machine**: server-priced quotes bound to the customer (10 min), placement with re-pricing (409 `quote_changed`), `Idempotency-Key`, stock reservation under row locks, coupon redemption, wallet payment in the same transaction, pay on delivery, order snapshots and encrypted handover code. State machine with the spec 04 transition table per actor and append-only status history. Customer endpoints: quote, place, list (active/past), details, cancel (stock, coupon and wallet refund returned). Scheduled orders and `orders:release-scheduled` (every minute); auto-accept stores. Coverage gate extended to the orders code. Notes and open points in `docs/plans/phase-2.md`.
- 2026-11-22 — Milestone 2.4 merged (PR #14).
- 2026-11-23 — **Milestone 2.5, Paystack and Flutterwave**: gateway clients (Paystack in kobo, Flutterwave in naira), payment attempts per order and top-up, server-side verification of status, reference, amount, currency and one-use transaction ids (tampered-amount, wrong-currency and replayed-transaction tests), signed and de-duplicated webhooks processed on the queue, wallet top-ups within tier limits, `payments:expire` for unpaid orders and top-ups, late payments returned to the wallet, daily `payments:reconcile` coverage check. Coverage gate extended to the payment code.
- 2026-11-23 — Milestone 2.5 merged (PR #27).
- 2026-11-23 — **Milestone 2.6, order fulfilment**: vendor inbox and actions (accept with prep time, reject/cancel with full refund, ready, pickup handover with code); admin-created riders and manual assignment (vendor-owned limits, capacity, unassign before pickup); rider jobs (picked up, deliver with code and three-try lock, failed delivery with photo); settlement at delivery (pay on delivery paid, cash owed by the collector, commission record, vendor and rider earnings); admin order list/detail, cancel with fault and cancellation fee, delivery override, fail, refunds with earnings reversals; `orders:monitor` for store timeouts, no-rider and stale alerts.
- 2026-11-23 — Milestone 2.6 merged (PR #28).
- 2026-11-23 — **Milestone 2.7a, payouts**: bank accounts checked with the gateway's account-resolve API (name match or admin review, masked numbers); payout requests with Idempotency-Key and a ₦1,000 minimum; admin approve (settle commission, VAT and cash owed, then transfer via Paystack or Flutterwave Transfers), reject, sync and account approval; transfer results from signed webhooks or `payouts:sync`; failures release the hold. Referrals, reviews and documents follow in 2.7b.
- 2026-11-23 — Milestone 2.7a merged (PR #29).
- 2026-11-23 — **Milestone 2.7b, referrals, reviews and documents**: referral reward (5%, max ₦5,000) on the referred customer's first delivered order, clawed back on a full refund within 7 days, admin void; unknown codes no longer block sign-up. Reviews of vendor, rider and products after delivery with recomputed ratings, admin hide/show, public store reviews. Vendor and rider documents with admin request, approve and reject.
- 2026-11-23 — Milestone 2.7b merged (PR #30).
- 2026-11-23 — **Milestone 2.8, notifications**: in-app inbox per app, FCM HTTP v1 push (service account from the environment, per-device tokens, invalid tokens removed, urgent new-order push on the `new_orders` channel) and email (receipt, refund, payouts, document requests), sent after commit through the queue. Domain events for order status, rider assignment, refunds, wallet credits, failed payments, payouts, documents and order alerts, routed to customers, store members or owners, and riders.
- 2026-11-23 — Milestone 2.8 merged (PR #31).
- 2026-11-23 — **Milestone 2.9a, parcels** (owner decisions: operator's own riders only; no VAT on parcels): package types, operator rates, operator comparison and quotes for multi-stop parcels (spec 05 §5.3 price), placement through the normal checkout with stops and per-stop codes, rider stop-by-stop completion, settlement of the whole price (less commission) to the operator, operator rate management, store rider list and assignment, admin package types.
- 2026-11-23 — Milestone 2.9a merged (PR #32).
- 2026-11-23 — Milestone 2.9b merged (PR #33). All Phase 2 milestones are on `main`.
- 2026-11-24 — The API moved to a new Laravel Cloud environment in the EU: `https://wegodeliva-platform-eu-production-q1ab72.laravel.cloud` (owner: all settings and deployments go there). Vercel `API_BASE_URL` updated and the admin panel redeployed; the US East environment is no longer used.
- 2026-11-24 — Phase 2 checked: every API endpoint is exercised by a feature test (one gap, rider inbox mark-read, now tested) and the payment tests cover tampered amounts, wrong currency and replayed transactions. Phase 3 plan drafted (`docs/plans/phase-3.md`) with owner decisions: Google Maps, hosted checkout in an in-app browser, KYC verification later, staging APK from each PR.
- 2026-11-23 — **Milestone 2.9b, service bookings** (owner decisions: 50% late-cancellation fee within 12 h; provider paid on confirmation): services as products of a services store (ADR 0014) with pricing unit, units, location, call-out fee, capacity and duration; free-slot lookup; booking quotes and placement through the normal checkout; provider confirm/decline, start with the customer's code, complete; customer confirm or report a problem; admin dispute resolution; `bookings:monitor` for acceptance timeouts, reminders and auto-confirmation. Phase 2 milestones are all built.

## In progress

- Owner: turn on the scheduler in Laravel Cloud (needed from 2.4 for scheduled orders; from 2.5 for payment timeouts and reconciliation; from 2.6 for store timeouts and order alerts; from 2.9b for booking timeouts, reminders and auto-confirmation).
- Owner (to test payments on staging): add Paystack and Flutterwave **test** keys to Laravel Cloud (`PAYSTACK_SECRET_KEY`, `FLUTTERWAVE_SECRET_KEY`, `FLUTTERWAVE_SECRET_HASH`), set `PAYMENT_PAYSTACK_ENABLED=true` / `PAYMENT_FLUTTERWAVE_ENABLED=true`, and set the webhook URLs on both dashboards to `https://wegodeliva-platform-eu-production-q1ab72.laravel.cloud/api/v1/webhooks/paystack` and `…/api/v1/webhooks/flutterwave`.
- Owner (before real payouts): enable Paystack Transfers and turn off the transfer OTP, get Flutterwave Transfers approved, and fund the transfer balance.
- Owner (push notifications): add the Firebase service-account key, base64-encoded, to Laravel Cloud as `FIREBASE_CREDENTIALS_BASE64`.
- Owner: queue worker on Laravel Cloud (needed from 2.8: every notification goes through the queue), Brevo (domain DNS + SMTP key), removing Supabase secrets from the Vercel project, deleting `SUPER_ADMIN_PASSWORD` from Laravel Cloud.

## Next

1. Before launch: turn off scale-to-zero; point the mobile staging flavour at `https://wegodeliva-platform-eu-production-q1ab72.laravel.cloud/api/v1`.
2. Phase 1 leftovers: admin TOTP; iOS flavour schemes + privacy manifest + Sign in with Apple capability (needs a Mac); FCM wiring once Firebase access is granted; OpenAPI generation for the API; `X-App-Version` / 426 check; move CI and code to Flutter 3.48 (`material.dart` deprecation) in its own PR.
3. Phase 3 note: the apps must show the phone re-confirmation screen when `phone_confirmation_required` is true and offer "set a password" after first verification (ADR 0012).
4. Phase 2 wrap-up after 2.9b is merged: check the Phase 2 "done" criteria (feature tests for every endpoint, tampered-amount and replayed-transaction payment tests) and agree the start of Phase 3 (customer app).

## Blockers / owner actions

**Needed for staging (Phase 1 "done" criterion). Nothing was created or paid for in this session.**
- **Supabase**: create (or give the team access to) the organisation and a staging project; enable PostGIS. Share the database connection strings via the host's secret store, not chat.
- **Laravel hosting**: choose Laravel Cloud or a VPS. The organisation already has the Laravel Cloud and Vercel GitHub apps installed, so Laravel Cloud is the shortest path.
- **Vercel**: team access for the `web/` project (root directory `web`, env `API_BASE_URL`).
- **Transactional email provider (S4)**: account + API key; SPF/DKIM/DMARC on wegodeliva.com. Until then OTP emails only work locally (Mailpit).
- **Firebase**: add the team as editor on the existing project (FCM only).
- **Google / Apple sign-in**: OAuth client IDs (Google Cloud console; Apple Services ID) — set `GOOGLE_CLIENT_IDS` / `APPLE_CLIENT_IDS`.

**Questions for the owner**
- Admin "permissions per role" (spec 12.2): fixed in code (ADR 0009) unless you want admins to edit them at runtime.

**Earlier items still open**
See [spec/open-questions.md](spec/open-questions.md) "Remaining questions":
- **S3** before launch: written confirmation from Paystack and Flutterwave that their terms allow stored customer balances and held earnings; fintech-lawyer opinion; accountant sign-off on the VAT table.
- **S4** Choose a transactional email provider for OTP and receipts (several have free tiers) and set up SPF/DKIM/DMARC on wegodeliva.com. The owner creates the account; the team adds the API key to secrets.
- **S5** Support WhatsApp numbers.
- Before the first store release (Phase 7): request an upload key reset in Play Console for each app, and give the team access to the Apple Developer account.
- **Secrets in legacy code** (not copied into this repo): restrict or rotate the Google Maps keys in the customer and rider apps.
- Leftover original copy of the customer app at `../Wegodeliva Apps/WegoDeliva CustomerApp`: owner can delete it.
