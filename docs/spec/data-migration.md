# Data migration: old MySQL → new Postgres (Supabase)

## M.0 Owner decision 2026-11-01 — the old data is test data

All vendors and customers on the old system are **test/demo users**. So:

- **No customer, vendor, order, wallet or earnings data is migrated.** The new platform starts with an empty production database (plus seed data: vendor types, categories, cities, campus zones, content pages, FAQs, settings).
- The Phase 7 wallet "reconciliation to the kobo" from CLAUDE.md does not apply to customers/vendors because no balances carry over. Before cut-over we still take a **read-only snapshot** of the old database from cPanel and confirm no real money is involved (any non-zero wallet/earnings balance, any successful real Paystack/Flutterwave payment). If real balances turn up, the owner decides case by case and §M.4 below applies to those accounts only.
- Loyalty points: the owner chose to convert points to wallet credit, but since all holders are test users there is nothing to convert. If the snapshot shows real holders, convert at the old rate.
- Riders: also test accounts (owner, R5). Nothing is migrated.
- Old production is on **cPanel**. It stays up, read-only, until the new apps are live, then is switched off (files and DB dump archived privately for 1 year).
- What still matters from the old system: the **app store identities** (§M.7), the Firebase project (kept, so FCM continues), the domain, and the Google Maps keys (restrict/rotate).

The table mapping below is kept **for reference only**, in case the snapshot shows real data that must be carried over.

## M.1 Principles

- One-off, scripted, repeatable ETL (extract from a MySQL dump → transform → load into Postgres), run in a staging environment first. No dual-write.
- New primary keys are fresh; every migrated row keeps `legacy_id` (and `legacy_table` where merged) for traceability and reconciliation. Drop these columns after sign-off + 90 days.
- Money: old `double` naira → `round(value × 100)` kobo. Any row where `abs(value × 100 − round(value × 100)) > 0.0001` is logged as a rounding exception.
- Phones normalised to E.164 (+234). Invalid phones → logged; user migrated with phone null and forced to re-verify at next login.
- Passwords: old bcrypt hashes are compatible with Laravel → copy as is (users keep their passwords).
- Personal-access tokens are **not** migrated → everyone logs in again after the app update (communicate this).
- Soft-deleted rows: migrate users/vendors/products that are referenced by orders (as deleted); drop the rest.
- Files: old media library rows point to files on the old server's disk/S3 → copy needed files (vendor logos/covers, product images, banners, category images, vendor/rider documents, prescriptions for orders in the last 12 months) into Supabase Storage private buckets; rewrite paths.
- Out-of-scope data (taxi, services, fleets, subscriptions…) is **archived** to a read-only export (CSV in a private bucket), not loaded, unless the owner keeps the module.

## M.2 Table mapping

| Old table(s) | New table(s) | Notes |
| --- | --- | --- |
| `users` | `users` | `code` → `referral_code` (re-generate if collides with new format? keep to preserve shared links). `is_active` → `status`. `vendor_id` → `vendor_members` (staff). `commission` → `rider_profiles.share_rate_bp` (riders) — confirm meaning. `language` → `locale`. `is_online` → `rider_profiles.is_online = false` at cut-over. `country_code` dropped. |
| `roles`, `model_has_roles`, `permissions`… | `user_roles` | Old role → new: `client` → customer; `manager` → vendor_owner (first manager per vendor) / vendor_staff (others) — owner to confirm who owns each vendor; `driver` → rider; `admin` → admin (super admin set manually); `city-admin` → zone_admin. Old permissions not migrated. |
| `password_resets`, `otps`, `personal_access_tokens`, `user_tokens`, `firebase_notifications` | — | Not migrated (ephemeral / re-registered by the new apps). |
| `vendor_types` | `vendor_types` | Map slugs: food, grocery→groceries, pharmacy, commerce→fashion, parcel/package→parcels. Rows for taxi/service/booking archived. |
| `vendors` | `vendors` | Fees ×100 to kobo; `commission` (%) → bp; `tax` (string %) → bp; `delivery_range` (km) → m; `charge_per_km` → `delivery_fee_mode`; `is_open` → `open_state`; `pickup`/`delivery` → accepts_*; `prepare_time`/`delivery_time` (strings) → minutes (parse; log failures); `is_active` → status; `featured` → is_featured; `in_order` → sort_order; lat/lng strings → numeric (log invalid). `has_drivers` → uses_own_riders. Subscription columns dropped (Q1). |
| `vendor_managers` | `vendor_members` | role staff. |
| `day_vendor` + `days` | `vendor_hours` | weekday from day name. |
| `vendor_open_statuses` | `vendors.open_state_source` | |
| `vendor_settings` | review case by case | JSON blob; map known keys. |
| `delivery_zones` + `delivery_zone_points` | `zones` | Points → polygon (close ring, validate; circle zones with radius → buffered polygon). |
| `delivery_zone_vendor` | `vendor_zones` | |
| `delivery_zone_vendor_type` | — | Drop (zones apply to all types) unless owner needs it. |
| `countries`, `states`, `cities`, `*_vendor` pivots | — | Archived (O5). |
| `categories`, `subcategories` | same | `in_order` → sort_order. |
| `category_vendor` | — | Derive from products' categories. |
| `menus`, `menu_product` | `vendor_menus`, product↔menu | |
| `products` | `products` | price/discount_price → price_kobo / compare_at logic (old "discount_price" is the *selling* price when set and > 0 → new `price_kobo` = discount price, `compare_at_price_kobo` = old price); `available_qty` → stock_qty; `capacity`+`unit` → unit_size/unit; `package_count` → pack_count; `plus_option` dropped; `digital` products archived (O10). |
| `category_product`, `product_subcategory`, `product_tag` | link tables | |
| `option_groups`, `options`, `option_product` | `option_groups`, `options`, `product_option_groups` | Old options had a product_id *and* a group; dedupe. |
| `tags` | `tags` | |
| `banners` | `banners` | link → link_type/target. |
| `favourites` | `favourites` | |
| `delivery_addresses` | `addresses` | description → landmark; zone computed; city/state/country strings kept in line fields. Parcel stop addresses also live here. |
| `package_types` | `package_types` | |
| `package_type_pricings` | `parcel_rates` | Money ×100. |
| `payment_methods` | `payment_methods` | Keep cash→pay_on_delivery, wallet, paystack, flutterwave; **do not migrate secret/public key columns**. Others archived. |
| `payment_method_vendor` | `vendor_payment_methods` | |
| `orders` (+ `statuses` morph table) | `orders` + `order_status_history` | See status map M.3. Amount columns ×100. `code` kept (customers may quote it). `verification_code` not migrated (orders are final). Taxi/service orders archived. Address snapshot built from `delivery_address_id` at migration time (best effort; flag that it reflects the address's current state). `fees` JSON → `order_fees`. Package columns → parcel fields. |
| `order_products` | `order_items` | Product name snapshot from current product (old rows had no snapshot — flag). options string → jsonb best effort. |
| `order_stops` | `order_stops` | |
| `order_services`, `taxi_orders`, `services*`, `service_option*`, `option_service`, `vehicle_types`, `car_makes`, `car_models`, `vehicles`, `payment_method_vehicle_type`, `taxi_*`, `driver_types`, `outstanding_balances` | — | Archived (O1, O2). Exception: `vehicles` rows of delivery riders → `rider_profiles` plate/colour. |
| `payments` | `payments` | gateway from order's payment method; status map: successful→succeeded, pending→abandoned (old), failed/review→failed. |
| `wallets` | `wallets` | balance ×100. **Reconciliation-critical.** |
| `wallet_transactions` | `wallet_transactions` | `is_credit` → direction; reason → kind (Topup→topup, Refund→refund, Transfer→legacy_transfer, others→legacy_other); `balance_after` recomputed in time order — see M.4. |
| `earnings`, `earneds`, `earning_reports`, `commissions` | `earnings_accounts`, `earnings_entries`, `commission_records` | Opening balance per vendor/rider = old `earnings.amount`; history from earning_reports as entries (informational). |
| `remittances` | `rider_cash_entries` | pending → outstanding cash; collected → remitted. |
| `payment_accounts` | `payout_accounts` | Accounts unverified → flagged for re-verification. |
| `payouts` | `payouts` | status map review→requested, successful→paid. |
| `refunds` | `refunds` | |
| `coupons`, `coupon_vendor`, `coupon_product`, `coupon_user` | `coupons`, links, `coupon_redemptions` | Expired coupons migrated inactive; `times` → max_uses_per_user (confirm meaning). |
| `referrals` | `referrals` | confirmed → rewarded. |
| `reviews`, `ratings`, `product_reviews` | `reviews` | Merge; recompute rating averages. |
| `auto_assignments` | — | Not migrated. |
| `document_requests` + media | `vendor_documents` / `rider_documents` | |
| `content_pages`, `faqs`, `onboardings`, `cancellation_reasons` | same | Review texts for template wording before load. |
| `push_notifications` | `broadcasts` | Optional (history only). |
| `fees`, `fee_vendor` | `fees`, `vendor_fees` | `for_admin` → beneficiary platform. |
| `loyalty_points`, `loyalty_point_reports` | — / archived | Loyalty is LATER (Q2): archive; convert outstanding points to wallet credit **only if owner decides** (they may have been promised value). |
| `flash_sales`, `flash_sale_items` | — / archived | Q4. |
| `subscriptions`, `subscription_vendor` | archived | Q1. |
| `fleets`, `fleet_user`, `fleet_vehicle` | archived | O9. |
| `settings` | `settings` | Map only the keys in [13-settings.md](13-settings.md); ignore theme/template keys; **never** migrate key/secret values. |
| `currencies`, `sms_gateways`, `extensions`, `nav_menus`, `media` (as table), `jobs`, `failed_jobs`, `statuses` (after order history), `password_resets` | — | Not migrated. |

## M.3 Status maps

Order status (old current status → new):
`delivered` → delivered; `cancelled` → cancelled; `failed` → failed; `pending`, `scheduled`, `preparing`, `ready`, `enroute` → **must be zero at cut-over** (drain: stop new orders, finish or cancel+refund open ones before the final export).

Payment status (old → new): `successful` → paid; `pending` → unpaid (pay-on-delivery delivered orders → paid); `failed`, `cancelled` → failed; `request`, `review` → unpaid (only on cancelled orders after draining).

## M.4 Wallet reconciliation (to the kobo)

For every user:
1. `old_balance_kobo = round(wallets.balance × 100)`.
2. `ledger_sum_kobo` = Σ successful credits − Σ successful debits from old `wallet_transactions`.
3. If they match → migrate the ledger as is.
4. If they differ (expected: the old system changed balances outside the ledger, e.g. some refunds and transfers) → migrate the ledger **plus one `migration_adjustment` transaction** for the difference, so the new balance equals the old balance exactly. Every adjustment is listed in a report for the owner.
5. Post-load check: new `wallets.balance_kobo` == `old_balance_kobo` for 100% of users; Σ all balances equal.

The same method applies to vendor/rider earnings balances and rider cash balances.

## M.5 Reconciliation report (Phase 7 acceptance)

- Users by role: old count = new count (+ list of excluded rows with reason).
- Vendors, products, orders by final status and by month: counts and Σ totals equal.
- Wallet, earnings and cash balances: per-account exact match (M.4).
- Payments succeeded: count and Σ amount equal.
- Spot checks: 50 random customers, 20 vendors, 20 riders compared field by field.

## M.6 Cut-over outline (detailed runbook in `docs/runbooks/` in Phase 7)

1. Announce maintenance window; force-update notice in old apps (if possible) shortly before.
2. Stop new orders on the old system; drain open orders.
3. Final MySQL dump → ETL → reconciliation → owner sign-off.
4. Switch DNS / API base URL; publish app updates (same package IDs).
5. Rollback: old system kept intact and read-only for 30 days; DNS switch back if a go/no-go check fails.

## M.7 App identity continuity

Owner answer 2026-11-01 (R1): **Play App Signing is enabled** for all three apps, and **Alpha Bytes Technologies owns the Apple Developer account**. So:

- Keep `com.wegodeliva.customerapp`, `com.wegodeliva.vendorapp`, `com.wegodeliva.riderapp` as the Android `applicationId` and iOS bundle id for production. Non-production flavours use `.dev` / `.staging` suffixes.
- **Android:** the old upload keystores' passwords are lost, so before the first Phase 7 release the owner requests an **upload key reset** in Play Console (Test and release → App integrity → App signing → Request upload key reset) for each app. The team generates the new upload keys and stores them in the CI secret store and the owner's vault. Google keeps the app signing key, so users receive updates normally. Allow a few days per app for Google to process the reset.
- **iOS:** the team creates new distribution certificates and provisioning profiles under the Alpha Bytes account (admin or App Manager access needed). Bundle ids are unchanged.
- Version codes and build numbers must be higher than the live ones: customer > 11, vendor > 3, rider > 2.
- The old `upload-keystore.jks` files in `../legacy/` are no longer needed and must never be copied.
