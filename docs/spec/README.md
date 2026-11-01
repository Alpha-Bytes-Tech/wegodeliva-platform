# WeGoDeliva — Functional Specification (Phase 0)

Status: **Draft for owner review** — 2026-11-01
Author: WeGoDeliva development team (Claude)

This specification describes **what the WeGoDeliva platform must do**. It was written by reading the old system once, in Phase 0, and restating its behaviour in our own words. It contains no legacy code, file structures, class names or comments. From Phase 1 onward the team builds from this folder, not from the old code.

Where the old system behaved badly (security holes, rounding problems, race conditions), this spec says what the **new** behaviour must be and records the old defect in [legacy-notes.md](legacy-notes.md) so we test against it.

## How to read this spec

| # | Document | Covers |
| --- | --- | --- |
| 1 | [01-scope.md](01-scope.md) | Product scope, verticals, **out-of-scope modules (needs owner confirmation)** |
| 2 | [02-roles-and-permissions.md](02-roles-and-permissions.md) | Actors, roles, what each can see and do |
| 3 | [03-entities.md](03-entities.md) | Entities and fields of the new data model |
| 4 | [04-order-lifecycle.md](04-order-lifecycle.md) | Order types, states, transitions, cancellation, scheduling, timeouts |
| 5 | [05-pricing-fees-commission.md](05-pricing-fees-commission.md) | Cart pricing, delivery fees, coupons, tax, service fees, commission, earnings |
| 6 | [06-payments-wallet-payouts.md](06-payments-wallet-payouts.md) | Paystack, Flutterwave, wallet, pay-on-delivery, refunds, payouts, referrals |
| 7 | [07-dispatch-and-delivery.md](07-dispatch-and-delivery.md) | Rider matching, job offers, proof of delivery, cash handling |
| 8 | [08-notifications.md](08-notifications.md) | Push, SMS/OTP, email, in-app messages |
| 9 | [09-customer-app.md](09-customer-app.md) | Every customer app screen |
| 10 | [10-vendor-app.md](10-vendor-app.md) | Every vendor app / vendor portal screen |
| 11 | [11-rider-app.md](11-rider-app.md) | Every rider app screen |
| 12 | [12-admin-panel.md](12-admin-panel.md) | Admin functions |
| 13 | [13-settings.md](13-settings.md) | Platform settings an admin can change |
| 14 | [14-api-behaviour.md](14-api-behaviour.md) | API conventions and endpoint groups |
| 15 | [data-migration.md](data-migration.md) | Old MySQL tables → new Postgres schema |
| 16 | [legacy-notes.md](legacy-notes.md) | Defects in the old system we must not repeat (the only file allowed to name the old template) |
| 17 | [open-questions.md](open-questions.md) | Business rules the owner must decide |

## Conventions used in this spec

- **Money** is stored as integer **kobo** (₦1 = 100 kobo), currency `NGN`. No floating-point money anywhere. Percentages are stored as basis points (1% = 100 bp).
- **Time** is stored in UTC and shown in `Africa/Lagos`.
- **Phone numbers** are stored in E.164 (`+234…`).
- "MUST" = required for launch; "SHOULD" = expected unless the owner says otherwise; "LATER" = after launch.
- Words: *customer* (buyer), *vendor* (store/restaurant/pharmacy/shop), *vendor staff* (people who work for a vendor), *rider* (delivery person; the old system called them "drivers"), *admin* (WeGoDeliva staff).
