# 0014. Services are products of a services store; bookings are orders

Date: 2026-11-23 · Status: Accepted

## Context
Spec 03 lists a separate `services` table with its own option groups and an order line pointing to `service_id`. Almost everything a service needs is already built for products: images, categories with VAT treatment, option groups and options, vendor management screens, catalogue endpoints, reviews and ratings, and order lines with snapshots. A parallel set of tables would duplicate all of it.

## Decision
- A service is a `products` row of a store whose vendor type is `services`, with service fields set:
  - `pricing_unit` (fixed / hour / day / month);
  - minimum and maximum units;
  - `location_mode`;
  - call-out fee mode and amount;
  - `capacity`;
  - `duration_minutes`.

  `pricing_unit` is null for goods. A services store must set these fields and cannot track stock; other stores cannot set them.
- Options gain `per_unit`. For services, an option is charged once per booking unless it is per unit; goods are unaffected (always per unit).
- A booking is an `orders` row of type `services`:
  - one order item: quantity 1, the price × units as its unit price, and `units` recorded;
  - order columns `units`, `starts_at`, `ends_at` and `service_location`;
  - its own statuses: requested, confirmed, in_progress, completed, disputed. These go through the same state machine, history, payments, refunds, settlement and notifications.
- Fulfilment is `delivery` for work at the customer's address (the call-out fee is the delivery fee) and `pickup` for work at the provider's.

## Consequences
- Reviews, favourites, search, images and option management work for services with no new code.
- The data migration maps old service rows to `products` with a pricing unit (noted in `docs/spec/data-migration.md` when Phase 7 is planned).
- Reports that mean "goods" must filter on `pricing_unit IS NULL` or on the order type.
