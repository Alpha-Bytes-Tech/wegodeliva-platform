# 3. Entities and fields

This is the **new** logical data model. It is designed for WeGoDeliva's scope, not copied from the old schema; [data-migration.md](data-migration.md) maps old tables onto it. Physical details (indexes, exact column types) are decided in Phase 1–2 migrations.

Common to every table unless stated: `id` (bigint identity), `created_at`, `updated_at`. Soft delete (`deleted_at`) only where noted. Money columns end in `_kobo` (bigint). Rates end in `_bp` (basis points, int). Coordinates are `numeric(9,6)` lat/lng, plus a PostGIS `geography(Point)` where we search by distance.

## 3.1 People and access

**users** — one row per person.
`uuid` (public id), `name`, `phone` (E.164, unique — **required**; social sign-ups complete it before using the app), `email` (unique — **required**), `email_verified_at`, `phone_verified_at`, `password_hash` (nullable for OTP-only users), `status` (active / pending_approval / suspended / deleted), `avatar_path`, `referral_code` (unique, short, human-friendly), `referred_by_user_id`, `locale` (default `en`), `last_seen_at`, soft delete.

**roles / user_roles** — role names from [02](02-roles-and-permissions.md). A user may hold several roles.

**auth_identities** — social sign-in links: `user_id`, `provider` (google/apple), `provider_user_id`.

**otp_challenges** — `phone`, `purpose` (login / verify / reset), `code_hash`, `expires_at`, `attempts`, `consumed_at`. Codes are stored hashed.

**devices** — FCM tokens: `user_id`, `app` (customer/vendor/rider/admin), `platform`, `fcm_token` (unique), `last_active_at`.

**admin_audit_log** — `actor_user_id`, `action`, `subject_type`, `subject_id`, `before` (jsonb), `after` (jsonb), `ip`, `user_agent`. Append-only.

## 3.2 Places

**cities** — `name` (Lagos, Ibadan, Abeokuta, Ago-Iwoye, Ile-Ife, Benin City…), `state`, `is_active`, `centre_lat`, `centre_lng`.

**zones** — delivery zones. `city_id`, `name` (e.g. "UNILAG main campus", "Surulere"), `polygon` (PostGIS polygon), `is_active`, `is_campus` (drives campus mode), `default_delivery_fee_kobo` (nullable), `rider_search_radius_m`, `notes`.

**addresses** — customer saved addresses and parcel stops. `user_id`, `label` (Home, Hostel…), `line1`, `landmark` (important in Lagos: "opposite the blue gate"), `area`, `city_id` (computed from the point), `lat`, `lng`, `zone_id` (computed), `campus_building` (nullable), `contact_name`, `contact_phone`, `is_default`, soft delete.

## 3.3 Vendors and catalogue

**vendor_types** — food / groceries / pharmacy / fashion / parcels. `slug`, `name`, `description`, `icon_path`, `color`, `sort_order`, `is_active`. The slug drives app behaviour (see [01](01-scope.md)).

**vendors** — `uuid`, `vendor_type_id`, `name`, `slug`, `description`, `logo_path`, `cover_path`, `phone`, `email`, `address_line`, `lat`, `lng`, `zone_id`, `status` (pending_approval / active / suspended), `is_featured`, `sort_order`,
`open_state` (open / closed / busy), `open_state_source` (schedule / manual), `accepts_delivery`, `accepts_pickup`, `allows_scheduled_orders`, `auto_accept_orders`, `uses_own_riders`, `prep_time_minutes`, `delivery_time_minutes` (estimate shown to customers),
`min_order_kobo`, `max_order_kobo` (nullable), `delivery_fee_mode` (zone / flat / per_km), `base_delivery_fee_kobo`, `delivery_fee_kobo` (flat amount or per-km rate), `delivery_radius_m`, `tax_rate_bp`, `commission_rate_bp` (nullable → platform default), `rating_avg`, `rating_count`, soft delete.

**vendor_members** — `vendor_id`, `user_id`, `role` (owner / staff).

**vendor_hours** — `vendor_id`, `weekday` (0–6), `opens_at`, `closes_at`. Several rows per day allowed (split shifts).

**vendor_documents** — `vendor_id`, `kind` (CAC certificate, pharmacy licence, ID…), `file_path` (private bucket), `status` (requested / submitted / approved / rejected), `reviewed_by`, `review_note`.

**vendor_zones** — which zones a vendor delivers to (many-to-many), with optional per-zone `delivery_fee_kobo` override.

**vendor_payment_methods** — which payment methods a vendor accepts (e.g. some refuse pay-on-delivery).

**categories** — `vendor_type_id`, `name`, `image_path`, `color`, `sort_order`, `is_active`. **subcategories** — `category_id`, `name`, `image_path`, `sort_order`, `is_active`.

**vendor_menus** — a vendor's own sections ("Rice dishes", "Drinks"): `vendor_id`, `name`, `sort_order`, `is_active`.

**products** — `vendor_id`, `name`, `description`, `sku`, `barcode`, `price_kobo`, `compare_at_price_kobo` (the old "discount price": if set and lower, the selling price is the lower one), `unit` (e.g. "kg"), `unit_size` (e.g. "500 g"), `pack_count`, `stock_qty` (null = unlimited), `is_active`, `is_featured`, `is_deliverable`, `requires_prescription`, `age_restricted`, `sort_order`, `rating_avg`, `rating_count`, soft delete.
Links: product ↔ categories, product ↔ subcategories, product ↔ vendor_menus, product ↔ tags (many-to-many). **product_images** — ordered images.

**option_groups** — per vendor: `name` ("Choose your drink"), `is_multiple`, `is_required`, `min_select`, `max_select`, `sort_order`, `is_active`. **options** — `option_group_id`, `name`, `description`, `price_kobo` (extra), `is_active`, `sort_order`. **product_option_groups** — attaches groups to products.

**tags** — `vendor_type_id`, `name`, `sort_order` (e.g. "Spicy", "Vegan", "Halal").

**banners** — `image_path`, `link_type` (vendor / product / category / url), `link_target`, `zone_id` (nullable), `is_featured`, `sort_order`, `starts_at`, `ends_at`, `is_active`.

**favourites** — `user_id`, `product_id` or `vendor_id`.

## 3.4 Parcels

**package_types** — `name` (Document, Small box, Large box…), `description`, `image_path`, `requires_stop_verification`, `sort_order`, `is_active`.

**parcel_rates** — per delivery operator (vendor of type parcels) and package type: `base_price_kobo`, `distance_rate_kobo` + `per_km` flag, `size_rate_kobo` + `per_kg` flag, `extra_stop_fee_kobo`, `max_advance_booking_days`, `auto_dispatch`, `requires_dimensions`, `is_active`.

## 3.5 Orders

**orders** — `uuid`, `code` (short human code shown to users, unique, unguessable), `customer_id`, `vendor_id`, `rider_id` (nullable), `type` (food / groceries / pharmacy / fashion / parcel), `fulfilment` (delivery / pickup), `status` (see [04](04-order-lifecycle.md)), `payment_status` (unpaid / pending / paid / failed / refunded / partially_refunded), `payment_method` (paystack / flutterwave / wallet / pay_on_delivery), `delivery_address_id` + **address snapshot** (jsonb — the order must not change if the customer later edits the address), `scheduled_for` (nullable), `note`, `cancel_reason`, `cancelled_by`,
amounts: `subtotal_kobo`, `discount_kobo`, `delivery_fee_kobo`, `delivery_discount_kobo`, `service_fee_kobo`, `tax_kobo`, `tip_kobo`, `total_kobo`, `tax_rate_bp`,
`coupon_id`, `handover_code_hash` (customer gives the code to the rider), `pickup_code_hash` (SHOULD: rider shows to vendor), `estimated_ready_at`, `estimated_delivery_at`, `placed_at`, `accepted_at`, `ready_at`, `picked_up_at`, `delivered_at`, `cancelled_at`, `distance_m`, `campus_mode`, soft delete only by admin.

Parcel orders also carry: `package_type_id`, `weight_g`, `length_cm`, `width_cm`, `height_cm`, `payer` (sender / recipient).

**order_items** — `order_id`, `product_id`, **snapshot** of `product_name`, `unit_price_kobo`, `quantity`, `options` (jsonb: group, option, price for each chosen option), `line_total_kobo`.

**order_fees** — breakdown of service fees applied: `order_id`, `fee_id`, `name`, `amount_kobo`, `beneficiary` (platform / vendor).

**order_stops** — parcel stops, in order: `order_id`, `sequence`, `kind` (pickup / dropoff), address snapshot, `contact_name`, `contact_phone`, `note`, `verification_code_hash`, `verified_at`.

**order_status_history** — `order_id`, `from_status`, `to_status`, `actor_user_id`, `actor_role`, `reason`, `created_at`. Every transition is recorded.

**order_attachments** — prescriptions, proof-of-delivery photo, signature: `order_id`, `kind`, `file_path` (private), `uploaded_by`.

**cancellation_reasons** — preset reasons an admin maintains, by audience (customer / vendor / rider).

**dispatch_offers** — rider job offers: `order_id`, `rider_id`, `status` (offered / accepted / declined / expired / withdrawn), `offered_at`, `responded_at`, `distance_to_pickup_m`.

## 3.6 Money

**payment_methods** — the four methods with `is_active`, display name, instructions, sort order. Gateway keys are **not** stored in the database (environment secrets).

**payments** — one per charge attempt: `order_id` or `wallet_topup_id`, `gateway` (paystack / flutterwave), `reference` (our unique reference sent to the gateway), `gateway_transaction_id`, `amount_kobo`, `currency`, `status` (initiated / pending / succeeded / failed / abandoned), `channel` (card / bank_transfer / ussd), `raw_verification` (jsonb, redacted), `verified_at`.

**webhook_events** — every inbound webhook: `gateway`, `event_type`, `gateway_event_id`/reference (unique → idempotency), `signature_valid`, `payload` (jsonb), `processed_at`, `processing_error`.

**wallets** — one per user: `user_id` (unique), `balance_kobo`, `currency`. Balance is a cached sum of the ledger and is only changed inside a DB transaction with a row lock.

**wallet_transactions** — the ledger: `wallet_id`, `direction` (credit / debit), `amount_kobo`, `balance_after_kobo`, `kind` (topup / order_payment / refund / referral_reward / admin_adjustment / reversal), `reference` (unique), `order_id`, `payment_id`, `status` (pending / succeeded / failed), `note`, `created_by`.

**coupons** — `code` (unique, case-insensitive), `description`, `discount_type` (percent / fixed), `value` (bp or kobo), `max_discount_kobo`, `min_order_kobo`, `applies_to` (order_subtotal / delivery_fee), `scope` (all / vendor_type / vendors / products), `starts_at`, `ends_at`, `max_uses_total`, `max_uses_per_user`, `first_order_only` (SHOULD), `is_active`, `created_by`, `funded_by` (platform / vendor). Links: coupon ↔ vendors, coupon ↔ products. **coupon_redemptions** — `coupon_id`, `user_id`, `order_id`, `discount_kobo`.

**fees** — service fees: `name`, `type` (percent / fixed), `value`, `beneficiary` (platform / vendor), `is_active`. **vendor_fees** — which fees apply to which vendor.

**commission_records** — per delivered order: `order_id`, `vendor_gross_kobo`, `platform_commission_kobo`, `vendor_net_kobo`, `commission_rate_bp`, `rider_delivery_fee_kobo`, `rider_share_kobo`, `rider_rate_bp`, `platform_delivery_margin_kobo`, `tip_kobo`, `computed_at`. Written exactly once per order.

**earnings_accounts** — one per vendor and one per rider: `owner_type`, `owner_id`, `balance_kobo`. **earnings_entries** — ledger like wallet_transactions (`credit`/`debit`, `kind`: order_earning / payout / cod_settlement / adjustment).

**rider_cash_balances** — cash a rider collected on pay-on-delivery orders and owes the platform: `rider_id`, `balance_kobo`; with ledger **rider_cash_entries** (`order_id`, `amount_kobo`, `kind`: collected / remitted / offset_against_earnings / adjustment).

**payout_accounts** — bank details for vendors/riders: `owner_type`, `owner_id`, `bank_code`, `bank_name`, `account_number`, `account_name` (verified via Paystack/Flutterwave resolve), `is_default`.

**payouts** — `owner_type`, `owner_id`, `payout_account_id`, `amount_kobo`, `status` (requested / approved / processing / paid / rejected / failed), `requested_at`, `reviewed_by`, `paid_at`, `transfer_reference`, `note`.

**refunds** — `order_id`, `amount_kobo`, `destination` (wallet / original_method), `status` (pending / succeeded / failed / cancelled), `reason`, `requested_by`, `processed_by`, `gateway_reference`.

**referrals** — `referrer_user_id`, `referred_user_id` (unique), `reward_kobo`, `status` (pending / rewarded / void), `rewarded_at`, `qualifying_order_id`.

## 3.7 Riders

**rider_profiles** — `user_id`, `rider_type` (wegodeliva_managed / independent / vendor_owned), `vehicle_kind` (bicycle / motorbike / car / on_foot), `plate_number`, `vehicle_colour`, `vendor_id` (set only for vendor_owned), `city_id`, `is_online`, `max_active_orders`, `last_lat`, `last_lng`, `last_location_at`, `rating_avg`, `rating_count`, `approved_at`.

**rider_documents** — like vendor_documents (ID, licence, vehicle papers, guarantor form).

**rider_locations** — recent location samples for tracking/history (short retention, e.g. 30 days).

## 3.8 Reviews

**reviews** — `order_id`, `author_user_id`, `subject_type` (vendor / rider / product), `subject_id`, `rating` (1–5), `comment`, `is_visible`. One review per order per subject.

## 3.9 Content and communication

**content_pages** — terms, privacy, about: `slug`, `title`, `body` (sanitised rich text), `is_active`.
**faqs** — `audience` (customer / vendor / rider), `question`, `answer`, `sort_order`, `is_active`.
**onboarding_slides** — `audience`, `title`, `body`, `image_path`, `sort_order`, `is_active`.
**broadcasts** — admin push campaigns: `title`, `body`, `audience` (all / customers / vendors / riders / zone), `link_type`/`link_target`, `sent_at`, `sent_by`.
**notifications** — per-user inbox: `user_id`, `title`, `body`, `data` (jsonb), `read_at`.
**support_tickets** — in-app issue reports: `order_id` (nullable), `user_id`, `category`, `message`, `attachments`, `status`, `assigned_to`, `resolution`.

## 3.10 Settings

**settings** — key/value (jsonb) store for platform settings in [13-settings.md](13-settings.md). Every change is written to the audit log. Secrets never live here.

## 3.11 Additions from owner decisions (2026-11-01)

**Services** (vertical in scope):
- **services** — `vendor_id`, `category_id`, `subcategory_id`, `name`, `description`, `images`, `pricing_unit` (fixed / hour / day / month), `price_kobo`, `compare_at_price_kobo`, `min_units`, `max_units`, `location_mode` (at_customer / at_provider / either), `callout_fee_mode` + `callout_fee_kobo`, `capacity` (simultaneous bookings), `duration_minutes` (for fixed services), `vat_treatment`, `is_active`, `sort_order`, rating fields, soft delete.
- **service_option_groups** / **service_options** — like product option groups; options may be `per_unit`.
- Orders of type `service` use `order_items` with `service_id` instead of `product_id`, plus order fields `units`, `starts_at`, `ends_at`, `service_location` (address snapshot or "at provider").

**VAT**: `categories.vat_treatment`, `products.vat_treatment` (nullable override), `services.vat_treatment`; `order_items.vat_rate_bp`, `order_items.vat_kobo`; `orders.vat_kobo` replaces `tax_kobo`.

**Wallet KYC**: `users.kyc_tier` (1–3), **kyc_verifications** — `user_id`, `kind` (bvn / nin / id_document / address), `provider`, `provider_reference`, `status`, `verified_at`, masked value only (never store full BVN/NIN in plain text; store a hash + last 4).

**Faults and fees**: `orders.fault` (customer / vendor / rider / platform / none), `orders.cancellation_fee_kobo`; refunds carry `fault`.

**Earnings**: `earnings_entries.kind` adds `commission_accrued`, `commission_settled`, `vendor_cash_owed`; `earnings_accounts` exposes gross, commission due, cash owed and available-to-withdraw (computed).

**Referrals**: `referrals.reward_rate_bp` (500), `reward_cap_kobo` (500,000), `qualifying_amount_kobo`, `reward_kobo`, `clawed_back_at`.

**Chat** (in scope at launch):
- **conversations** — `kind` (order_customer_vendor / order_customer_rider / support), `order_id` (nullable), `status` (open / closed), `closed_at`. Order conversations close 24 h after the order ends (read-only afterwards).
- **conversation_participants** — `conversation_id`, `user_id`, `role`, `last_read_at`.
- **messages** — `conversation_id`, `sender_user_id`, `body` (text, length-limited), `attachment_path` (images only, private bucket), `created_at`, `deleted_at` (moderation). Phone numbers and links in messages are allowed but flagged for moderation (SHOULD).
- Messages are written through Laravel (validated, rate-limited, stored in Postgres) and broadcast on Supabase Realtime channel `conversation:{uuid}` authorised by a Laravel-minted JWT; RLS limits reads to participants.

**VAT (2026-11-01, R3)**: `vendors.vat_registered` (bool), `vendors.tin`, `vendors.vat_certificate_path` (private). `commission_records` gains `vendor_commission_vat_kobo`, `rider_commission_vat_kobo`. `categories.vat_treatment` defaults come from the table in [05](05-pricing-fees-commission.md) §5.6.
**Wallet (R4, owner decision)**: `wallet_transactions.kind` gains `promo_credit`. Cash top-ups on at launch; all funds sit in WeGoDeliva's Paystack/Flutterwave merchant balances, so every ledger entry records its `gateway`. **reconciliation_runs**: `run_date`, `ledger_total_kobo`, `gateway_balances` (jsonb per gateway), `in_flight_kobo`, `difference_kobo`, `status`, `report_path`.
