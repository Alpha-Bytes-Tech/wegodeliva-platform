# Legacy notes

This is the **only** file in the repository allowed to name the old template (the clean-room check skips it).

## Origin of the old system

The old WeGoDeliva apps and backend were built on a commercial multivendor template sold as "Glover" (backend/admin) with Flutter apps descended from "Fuodz". Its author's names appear in package names and settings. The old rider app's Dart package name is still the template's name. None of this may appear in the new code, package names, strings, assets or store listings.

Old code location: `../legacy/` (outside this repo, read-only, Phase 0 only).
- `legacy/backend` — Laravel 8-era app (Livewire admin panel, REST API, ~1,600 files, 108 migrations, 50 upgrade scripts).
- `legacy/customer-app`, `legacy/vendor-app`, `legacy/rider-app` — Flutter apps (Provider/Stacked-style view models).

## Secrets found in the old code (reported to owner 2026-11-01)

Not copied anywhere. Actions are tracked in `docs/progress.md`.
- Google Maps API keys hard-coded in the customer and rider apps (Android manifest, iOS app delegate). → Restrict to package/bundle + SHA-1, or rotate.
- Firebase config files (`google-services.json`, `GoogleService-Info.plist`) in all three apps. → New Firebase project or re-download; never commit.
- `upload-keystore.jks` in all three apps' Android folders. → **Critical for Phase 7**: these (or the Play App Signing setup) are needed to publish updates under the existing package IDs. Owner to store them in a password manager / secure vault with their passwords; never commit.

## Defects in the old system that the new system must not repeat

Each defect gets at least one regression test in Phase 2.

| # | Area | Old behaviour | New rule (spec ref) |
| --- | --- | --- | --- |
| D1 | Sign-up | The registration endpoint accepted a role name from the client, so anyone could register with an elevated role. | Roles assigned server-side only ([02](02-roles-and-permissions.md) §2.1). |
| D2 | Payments | Gateway verification checked only "status = success"; it did not compare amount, currency or reference with the order, and one gateway's verification used a transaction id supplied in the callback URL. A cheap successful payment could mark an expensive order paid. | Verify reference + amount + currency server-side ([06](06-payments-wallet-payouts.md) §6.2). |
| D3 | Webhooks | Webhooks were identified by a secret path segment only; payloads were not signature-checked and then triggered a server-side GET to the callback route. | Verify Paystack/Flutterwave signatures; idempotent event store ([06](06-payments-wallet-payouts.md) §6.3). |
| D4 | Orders | The customer could set the order's payment status in the order update call; the update also mass-assigned all request fields onto the order. | Status changes only via explicit actions and the state machine ([04](04-order-lifecycle.md)). |
| D5 | Orders | The orders list accepted any vendor id or driver id as a filter, and order details had no ownership check — any logged-in user could read other people's orders (addresses, phones). | Policies on every resource; 404 for others' data ([02](02-roles-and-permissions.md) §2.2). |
| D6 | Parcels | Parcel order totals were taken from the client request; the signed summary token was optional. | Server-side quote, re-computed at placement ([04](04-order-lifecycle.md) §4.4). |
| D7 | Pricing | Option prices were added once per line rather than per unit; product-scoped coupons applied the cap incorrectly (cap added instead of limiting); coupon expiry and usage limits were not checked when computing the summary. | [05](05-pricing-fees-commission.md) §5.1, §5.4. |
| D8 | Money | Money stored as floating-point `double`; balances updated with read-modify-write and no row locks (double-spend and lost updates possible under concurrency). | Integer kobo, ledgers, row locks, idempotency ([03](03-entities.md), [06](06-payments-wallet-payouts.md)). |
| D9 | Refunds | When "collect delivery fee in cash" was on, the wallet was credited a different amount from the one recorded on the refund transaction. | One amount, ledgered ([06](06-payments-wallet-payouts.md) §6.6). |
| D10 | Wallet | A balance-transfer endpoint took the recipient user id from the request with no password/OTP re-check; another transfer path did check the password. | Transfers out of scope (Q3); any future version needs OTP + limits. |
| D11 | Notifications | Push topics were named after user ids; any app instance could subscribe to another user's topic. | Token-based delivery per user ([08](08-notifications.md) §8.2). |
| D12 | Stock | Stock decremented without checking availability or locking; could go negative; returned on cancel without checking it was reserved. | Reserve with lock at placement ([04](04-order-lifecycle.md) §4.4). |
| D13 | Payouts | Payout request filled all request fields onto the payout row (vendor could name another vendor's id). Earnings deducted only when marked successful, so several requests could exceed the balance. | Hold on approval; owner-scoped ([06](06-payments-wallet-payouts.md) §6.7). |
| D14 | Order codes | Codes generated with a non-cryptographic random function; the 5-character verification code was stored in plain text and returned in order payloads. | Unguessable codes; handover code hashed, shown only to the customer. |
| D15 | Cancellation | The "statuses in which cancellation is blocked" logic was inverted for the default case (blocked cancelling `pending` orders when unset). | Explicit allowed-statuses setting ([04](04-order-lifecycle.md) §4.2). |
| D16 | Errors | Exception messages (including SQL/stack details) were returned to clients. | Generic messages; details in logs ([14](14-api-behaviour.md)). |
| D17 | Earnings | Earnings computed in model observers on every order save, with nested transactions and swallowed exceptions — partial earnings possible. | Single idempotent post-delivery job ([04](04-order-lifecycle.md) §4.7). |
| D18 | Stale sweep | A 6-hourly job cancelled *any* non-final order from a previous day — including orders already picked up — and the second query in that job never executed. | Alert admins; never auto-cancel picked-up orders ([04](04-order-lifecycle.md) §4.6). |
| D19 | Gateway keys | Payment gateway secret keys stored in the database and editable in the admin UI. | Environment secrets only ([06](06-payments-wallet-payouts.md) §6.1). |

## Old identifiers needed for continuity

- Android/iOS app IDs: `com.wegodeliva.customerapp`, `com.wegodeliva.vendorapp`, `com.wegodeliva.riderapp` (same on both platforms). Old version names: customer 3.0.0 (build 11), vendor 2.0.0 (build 3), rider 1.5.0 (build 2). New builds must use **higher** version codes.
- Old order statuses and payment statuses (for migration mapping): see [data-migration.md](data-migration.md).
