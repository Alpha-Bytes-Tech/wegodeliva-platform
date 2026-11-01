# 5. Pricing, fees and commission

All arithmetic is in integer kobo. Percentages are basis points. **Rounding rule:** each computed component is rounded half-up to the nearest kobo at the point it is computed; totals are sums of rounded components. Totals are shown to the kobo; no rounding to the nearest ₦10.

## 5.1 Item price

- Selling price of a product = `compare_at_price` logic: if a lower promotional price is set, use it; otherwise the normal price.
- Line total = (unit selling price + sum of chosen option prices) × quantity.
  (The old system added option prices once per line instead of per unit — defect D7. New rule: options are per unit.)
- Option rules are validated server-side: required groups must have a selection; selections must respect min/max; options must belong to the product's groups.

**Subtotal** = sum of line totals.

## 5.2 Delivery fee (regular orders)

Computed for delivery orders only (pickup = ₦0). Order of precedence, as in the old system:

1. **Zone fee** — if the vendor has delivery zones and the drop-off point lies inside one of them that defines a fee, use that zone's fee (vendor-specific override first, then the zone default).
2. Otherwise **vendor fee**:
   - flat: `delivery_fee`
   - per km: `delivery_fee × distance_km`
   - plus `base_delivery_fee` in both cases.
3. Distance is **road distance** from a maps API when enabled (cached per origin/destination pair for a short time), falling back to straight-line distance × 1.3 if the API fails.
4. If the drop-off is outside the vendor's `delivery_radius` and not in any of its zones → not deliverable (app shows "Pickup only" or blocks).
5. Platform defaults (settings) apply when the vendor has not set its own fee.

Campus mode (SHOULD): when both vendor and drop-off are inside the same campus zone, use the campus zone's flat fee.

## 5.3 Parcel price

For parcel orders, with the operator's rate for the chosen package type:
- distance part = `base_price` + (`distance_rate × total_route_km` if per-km, else `distance_rate`);
- size part = `size_rate × weight_kg` if per-kg, else `size_rate`;
- extra stops: `extra_stop_fee × (number_of_stops − 2)` — charged **per additional stop** beyond pickup + one drop-off (owner decision 2026-11-01; the old system charged for all stops once there were more than two).
- `route_km` is the sum of distances between consecutive stops.
- Each stop must be inside a zone served by the operator.
- Subtotal (parcel) = distance part + size part; this is what commission applies to.

## 5.4 Coupons

Validation (all server-side, at quote **and** at placement):
- active, inside start/end dates, total uses and per-user uses not exceeded, first-order-only respected;
- scope matches: all / vendor type / listed vendors / listed products;
- subtotal ≥ `min_order` (for product-scoped coupons: based on the subtotal of matching lines).

Amount:
- `applies_to = order_subtotal`: percent of the eligible subtotal (whole subtotal, or matching lines only for product scope), or a fixed amount; capped by `max_discount`; never more than the eligible subtotal.
- `applies_to = delivery_fee`: percent or fixed off the delivery fee; capped; never more than the delivery fee.
- One coupon per order.
- Who pays for it (`funded_by`): **vendor by default** (owner decision 2026-11-01) — the discount reduces the vendor's earnings. Admins can mark a coupon platform-funded (e.g. a launch campaign); then vendor earnings are computed on the undiscounted subtotal. Delivery-fee coupons are always platform-funded (the vendor doesn't receive the delivery fee).

## 5.5 Service fees

Each vendor can have zero or more fees (e.g. "Service charge", "Packaging"):
- percent of subtotal, or fixed amount;
- `beneficiary`: platform (kept by WeGoDeliva) or vendor (added to vendor earnings).

## 5.6 Tax

Owner decisions 2026-11-01: prices are **not** VAT-inclusive; VAT is **not** charged on delivery fees or service fees; WeGoDeliva is VAT-registered.

- VAT applies to **goods and services sold by vendors** (item lines and service bookings) at the standard rate (7.5%, a setting).
- Basis: the **discounted** item amount — `vat = rate × (vatable_subtotal − vendor-funded discount on vatable lines)`. Rationale: VAT is due on the consideration actually charged; a vendor-funded discount reduces it. (Old system: before discount.) Platform-funded discounts do not reduce the VAT base.
- **Exempt items**: Nigerian VAT law exempts some supplies, notably basic food items and medical/pharmaceutical products. Each category (and, as an override, each product) has a `vat_treatment`: standard / exempt / zero-rated. Default for food, groceries and pharmacy categories: to be set by the accountant before launch.
- VAT is shown as its own line at checkout and on receipts; each order stores the VAT per line.
- ⚠ **Accountant to confirm before launch** (team cannot give tax advice): (a) whether WeGoDeliva charges and remits VAT on vendors' goods as the marketplace operator, or each VAT-registered vendor does; (b) the exempt list per category; (c) VAT on WeGoDeliva's own commission charged to vendors/riders (normally yes — that is WeGoDeliva's own service). The system supports both (a) options via a setting.

## 5.7 Total

`total = (subtotal − discount) + (delivery_fee − delivery_discount) + service_fees + tax + tip`

- Tip is optional, chosen at checkout, 100% to the rider (never commissioned).
- Total must be ≥ ₦0; the minimum/maximum order checks use the subtotal.

## 5.8 Commission and earnings

Rates (owner decision 2026-11-01), both settings with per-vendor / per-rider overrides:
- **Vendor commission: 5%** of the vendor's goods/services value.
- **Delivery commission: 3%** of the delivery fee — the rider keeps 97% of the delivery fee, plus 100% of tips.

**Commission is deducted when the vendor or rider withdraws** (owner decision): earnings are credited gross at delivery, the commission accrues against them, and it is settled out of the payout.

At delivery (once per order, idempotent, one DB transaction):
- `vendor_gross` = subtotal − vendor-funded discount + vendor-beneficiary fees. (VAT is not vendor revenue: it is held for remittance per §5.6.)
- `vendor_commission` = 5% × (subtotal − vendor-funded discount).
- `rider_gross` = delivery fee (undiscounted; delivery coupons are platform-funded) + tip.
- `rider_commission` = 3% × delivery fee (never on tips).
- Write `commission_records` with all of these.
- Vendor earnings ledger: **credit** `vendor_gross` (kind `order_earning`) and **accrue** `vendor_commission` (kind `commission_accrued`, a pending debit).
- Rider earnings ledger: **credit** `rider_gross`, **accrue** `rider_commission`.
- Pay-on-delivery: the rider collected `total` → add to the rider's **cash balance** (owed to WeGoDeliva) — see [06](06-payments-wallet-payouts.md) §6.5.

Balances shown to vendors/riders:
- **Gross earnings**, **commission due**, **cash owed** (riders), and **available to withdraw** = gross − commission due − cash owed − pending payouts.

At withdrawal (payout approval): post a debit settling all accrued commission (and, for riders, the cash owed) and a debit for the payout amount; the transfer amount = what the user requested, which can never exceed "available to withdraw". VAT on WeGoDeliva's commission (if the accountant confirms it applies) is shown on the payout statement.

Vendor-owned riders: the delivery fee belongs to the vendor (credited to the vendor's earnings; the 3% delivery commission applies); the vendor pays its own riders outside the platform. Independent and WeGoDeliva-managed riders are credited directly.

Self-pickup orders: no delivery fee; vendor earnings as above. If the customer paid on pickup (pay on delivery at the counter), the vendor collected the cash, so the order total (minus vendor gross) is posted as an amount owed by the vendor, settled at the vendor's next withdrawal.

Platform revenue per order = vendor commission + delivery commission + platform-beneficiary service fees − platform-funded discounts − referral rewards.

Worked example (food order, delivered, paid online): items ₦5,000, vendor coupon −₦500, VAT 7.5% on ₦4,500 = ₦337.50, delivery ₦1,000, service fee (platform) ₦100, tip ₦200 → customer pays ₦6,137.50. Vendor gross ₦4,500, vendor commission ₦225 → vendor can withdraw ₦4,275. Rider gross ₦1,200, commission ₦30 → rider can withdraw ₦1,170. Platform keeps ₦225 + ₦30 + ₦100 = ₦355 and holds ₦337.50 VAT for remittance.

## 5.9 Service booking price

- Fixed-price service: `price + chosen options`.
- Time-based service (per hour / day / month): `(unit price × booked units) + chosen options` (options once per booking unless the option is marked per unit).
- Optional call-out/travel fee when the service is performed at the customer's address (vendor setting; flat or per km) — treated like a delivery fee (no VAT, no rider; the vendor keeps it minus the 3% delivery commission).
- Coupons, service fees, VAT and the 5% vendor commission apply exactly as for goods.

## 5.10 Displayed estimates

- Delivery time estimate = vendor prep time + travel time estimate (distance / average speed setting) — shown as a range.
- Vendor list shows "from ₦X delivery" using the customer's current location.
