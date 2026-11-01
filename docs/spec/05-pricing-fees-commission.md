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

## 5.6 Tax (VAT)

Owner decisions 2026-11-01: prices are **not** VAT-inclusive; VAT is **not** charged on delivery fees or service fees; WeGoDeliva is VAT-registered; **each vendor charges and remits VAT on its own goods and services**; **WeGoDeliva charges VAT on its commission**.

### VAT on vendors' goods and services (vendor's liability)
- WeGoDeliva only **calculates, shows and passes on** the vendor's VAT; the vendor files and remits it. WeGoDeliva does not account for this VAT.
- Vendor settings: `vat_registered` (yes/no) and `tin` (Tax ID, checked at onboarding). Only VAT-registered vendors charge VAT. A vendor below the registration threshold, or not registered, charges no VAT, and the customer sees no VAT line. (Research note: reports on the Nigeria Tax Act 2025 put the VAT registration threshold at roughly ₦50m turnover, up from ₦25m; some sources say ₦100m. Vendors decide their own status with their advisers; WeGoDeliva only records it.)
- Rate: standard 7.5% (setting).
- Basis: the discounted line amount. `vat = rate × (vatable lines − vendor-funded discount on those lines)`. VAT is due on what the customer is actually charged, and coupons are vendor-funded by default. Platform-funded discounts do not reduce the base.
- Each category has a `vat_treatment`, which a product or service can override: `standard` (7.5%), `zero_rated` (0%, the vendor can still reclaim input VAT) or `exempt` (no VAT). The customer sees no VAT for zero-rated or exempt items. The distinction matters only for the vendor's own returns, so it appears on vendor statements.
- VAT is shown as its own line at checkout and on receipts, and each order line stores its VAT rate and amount. Vendor statements give per-period VAT totals (standard / zero-rated / exempt sales) so vendors can file.
- VAT collected passes to the vendor's earnings in full. **Commission is never charged on the VAT.**

### Proposed default VAT treatment per category (research, 2026-11-01; accountant to confirm)
The Nigeria Tax Act 2025 (in force 1 January 2026) treats basic food and medical/pharmaceutical products favourably. Secondary sources disagree on whether some items are zero-rated or exempt, and the Minister can change the lists by order. The defaults below are therefore editable settings, not hard-coded:

| Category group | Default | Note |
| --- | --- | --- |
| Basic/unprocessed food: grains, rice, beans, garri, yam, tubers, fresh fruit & vegetables, fresh meat & fish, eggs, bread, cooking oil, salt, water | zero_rated | "Basic food items" |
| Baby food and baby products | exempt | Listed as exempt in reports on the new Act |
| Medical and pharmaceutical products, including registered herbal medicines | zero_rated | Some sources say exempt; the customer pays 0% either way |
| Medical equipment and assistive devices | exempt/zero_rated | Accountant to confirm |
| Sanitary pads, tampons and similar | exempt | Listed in reports on the new Act |
| Educational books and materials | zero_rated | |
| Restaurant / prepared / cooked food, snacks, drinks (food vendors) | standard | Prepared meals are a taxable supply, not "basic food items" |
| Packaged and processed groceries (beverages, confectionery, toiletries, household goods) | standard | |
| Fashion and general merchandise | standard | |
| Services (laundry, cleaning, beauty, repairs) | standard | Medical and educational services follow their own exemptions |

Sources consulted: TheCable ("Understanding reliefs and exemptions in Nigeria's 2026 tax reforms"; "Full list: items exempted from VAT in new tax law"), Mondaq ("VAT provisions under the Nigeria Tax Act, 2025"), EY tax alert ("Nigeria Tax Act, 2025 has been signed"), Forvis Mazars ("VAT & Nigeria 2025 tax reforms"). ⚠ The accountant must sign off this table against the gazetted Act before launch.

### VAT on WeGoDeliva's commission (WeGoDeliva's liability)
- WeGoDeliva charges 7.5% VAT **on its commission** (the 5% vendor commission and the 3% delivery commission), and accounts for and remits that VAT.
- It is accrued and deducted with the commission at withdrawal (§5.8). Statements show it as a separate line with WeGoDeliva's TIN, so VAT-registered vendors can claim it as input VAT.
- Delivery fees and service fees charged to customers carry no VAT, per the owner's decision. ⚠ The accountant should confirm this, because platform service fees are WeGoDeliva's own taxable supply.

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
- `vendor_gross` = subtotal − vendor-funded discount + vendor-beneficiary fees + **VAT collected on the vendor's behalf** (passed through; the vendor remits it, §5.6).
- `vendor_commission` = 5% × (subtotal − vendor-funded discount) — never on VAT; plus `vendor_commission_vat` = 7.5% × vendor_commission.
- `rider_gross` = delivery fee (undiscounted; delivery coupons are platform-funded) + tip.
- `rider_commission` = 3% × delivery fee (never on tips); plus `rider_commission_vat` = 7.5% × rider_commission.
- Write `commission_records` with all of these.
- Vendor earnings ledger: **credit** `vendor_gross` (kind `order_earning`) and **accrue** `vendor_commission` (kind `commission_accrued`, a pending debit).
- Rider earnings ledger: **credit** `rider_gross`, **accrue** `rider_commission`.
- Pay-on-delivery: the rider collected `total` → add to the rider's **cash balance** (owed to WeGoDeliva) — see [06](06-payments-wallet-payouts.md) §6.5.

Balances shown to vendors/riders:
- **Gross earnings**, **commission due (incl. VAT on commission)**, **cash owed** (riders), and **available to withdraw** = gross − commission due − VAT on commission − cash owed − pending payouts.

At withdrawal (payout approval): post debits settling all accrued commission and its VAT (and, for riders, the cash owed), and a debit for the payout amount; the transfer amount = what the user requested, which can never exceed "available to withdraw". The payout statement is a tax invoice for WeGoDeliva's commission (commission, 7.5% VAT, WeGoDeliva TIN).

Vendor-owned riders: the delivery fee belongs to the vendor (credited to the vendor's earnings; the 3% delivery commission applies); the vendor pays its own riders outside the platform. Independent and WeGoDeliva-managed riders are credited directly.

Self-pickup orders: no delivery fee; vendor earnings as above. If the customer paid on pickup (pay on delivery at the counter), the vendor collected the cash, so the order total (minus vendor gross) is posted as an amount owed by the vendor, settled at the vendor's next withdrawal.

Platform revenue per order = vendor commission + delivery commission + platform-beneficiary service fees − platform-funded discounts − referral rewards.

Worked example (restaurant meal from a VAT-registered vendor, delivered, paid online): items ₦5,000, vendor coupon −₦500, VAT 7.5% on ₦4,500 = ₦337.50, delivery ₦1,000, platform service fee ₦100, tip ₦200 → **customer pays ₦6,137.50**.
- Vendor: gross ₦4,500 + VAT ₦337.50 = ₦4,837.50; commission ₦225 + VAT on commission ₦16.88 → **can withdraw ₦4,595.62**, and remits ₦337.50 VAT itself (it can claim ₦16.88 as input VAT).
- Rider: gross ₦1,200; commission ₦30 + VAT ₦2.25 → **can withdraw ₦1,167.75**.
- WeGoDeliva: revenue ₦225 + ₦30 + ₦100 = **₦355**; collects ₦19.13 VAT on commission and remits it.
- Check: 4,595.62 + 1,167.75 + 355 + 19.13 = 6,137.50 ✔.
- Same order from a non-VAT-registered vendor, or for zero-rated groceries: no VAT line; the customer pays ₦5,800.

## 5.9 Service booking price

- Fixed-price service: `price + chosen options`.
- Time-based service (per hour / day / month): `(unit price × booked units) + chosen options` (options once per booking unless the option is marked per unit).
- Optional call-out/travel fee when the service is performed at the customer's address (vendor setting; flat or per km) — treated like a delivery fee (no VAT, no rider; the vendor keeps it minus the 3% delivery commission).
- Coupons, service fees, VAT and the 5% vendor commission apply exactly as for goods.

## 5.10 Displayed estimates

- Delivery time estimate = vendor prep time + travel time estimate (distance / average speed setting) — shown as a range.
- Vendor list shows "from ₦X delivery" using the customer's current location.
