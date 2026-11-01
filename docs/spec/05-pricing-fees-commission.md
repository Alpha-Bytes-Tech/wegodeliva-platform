# 5. Pricing, fees and commission

All arithmetic is in integer kobo. Percentages are basis points. **Rounding rule:** each computed component is rounded half-up to the nearest kobo at the point it is computed; totals are sums of rounded components. (Owner may prefer rounding customer-facing totals to the nearest ₦10 — open question.)

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
- extra stops: if there are more than 2 stops (pickup + 1 drop-off), add `extra_stop_fee × number_of_stops` — **owner to confirm**: the old system charged for *all* stops once there were more than two; a fairer rule is per *additional* stop.
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
- Who pays for it (`funded_by`): platform-funded discounts do not reduce vendor earnings; vendor-funded discounts do. (The old system always reduced the vendor's earnings — owner to confirm.)

## 5.5 Service fees

Each vendor can have zero or more fees (e.g. "Service charge", "Packaging"):
- percent of subtotal, or fixed amount;
- `beneficiary`: platform (kept by WeGoDeliva) or vendor (added to vendor earnings).

## 5.6 Tax

- `tax = vendor.tax_rate × subtotal` (old behaviour: on subtotal **before** discount).
- **Owner to confirm** with an accountant: VAT 7.5% basis (before or after discount; whether it applies to delivery and service fees); whether prices are shown VAT-inclusive (common in Nigeria) — in that case tax is informational, not added.

## 5.7 Total

`total = (subtotal − discount) + (delivery_fee − delivery_discount) + service_fees + tax + tip`

- Tip is optional, chosen at checkout, 100% to the rider (never commissioned).
- Total must be ≥ ₦0; the minimum/maximum order checks use the subtotal.

## 5.8 Commission and earnings (on delivery)

Rates: vendor commission = vendor's own rate, else platform default. Rider share = rider's own rate, else platform default (the old system called it "driver commission" and expressed it as the rider's percentage of the delivery fee).

Per delivered order:
- **Platform commission** = vendor_rate × subtotal.
- **Vendor net** = subtotal − platform commission − vendor-funded discount + vendor-beneficiary fees.
- **Rider share** = rider_rate × delivery fee (before delivery discount, if the platform funded it) + tip.
- **Platform delivery margin** = delivery fee − rider share (excluding tip).
- Platform-beneficiary service fees are platform revenue.

Crediting:
- Vendor net → vendor earnings account (credit).
- Rider share → rider earnings account (credit).
- If the order was **pay on delivery**, the rider collected `total` in cash/transfer: add `total` to the rider's cash balance (owed to the platform). Settlement: the rider remits cash (bank transfer to WeGoDeliva, verified) or it is netted off their earnings at payout time.
- Self-pickup pay-at-counter orders (if allowed): the vendor collected the cash, so the vendor's earnings account is **debited** by the platform commission and fees instead.

All of this runs once per order (unique `commission_records.order_id`) inside one DB transaction; a re-run is a no-op.

## 5.9 Displayed estimates

- Delivery time estimate = vendor prep time + travel time estimate (distance / average speed setting) — shown as a range.
- Vendor list shows "from ₦X delivery" using the customer's current location.
