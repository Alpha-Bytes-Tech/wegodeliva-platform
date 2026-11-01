# 7. Dispatch and delivery

## 7.1 What the old system did (summary)

- Riders' live positions were written to a cloud document store with geohashes; a server job looked up online riders within a search radius of the pickup point.
- For each candidate (skipping riders who already had a pending offer, had rejected this order, were too far, were inactive/offline, or already carried the maximum number of active orders), it created a pending "auto-assignment" and pushed a "New order alert" with pickup/drop-off, distances and the delivery fee to the rider.
- Offers expired after an "alert duration" (default 15–60 s); the job re-ran every alert duration + 10 s, widening to new riders.
- Vendor-owned riders only received that vendor's orders.
- Admin and vendor could also assign a rider manually.
- Riders sent location updates every N seconds or after moving N metres.
- Proof of delivery: a verification code, a photo, or a signature, depending on a global setting. Parcel stops could require per-stop verification.
- Riders had to keep a wallet balance to take cash orders (optional setting).

## 7.2 Matching (MUST)

Trigger: order enters `accepted` (or `ready` for parcels with auto-dispatch). Pickup point = vendor location (or first parcel stop).

Candidate riders:
- `active` account, approved, `is_online`, location updated in the last 2 minutes;
- within the zone's search radius of pickup (default 3 km; campus zones smaller);
- active jobs < `max_active_orders` (default 1; setting allows batching later);
- not currently holding another open offer; has not declined/expired this order;
- if the vendor uses its own riders: only that vendor's riders (fallback to platform riders after N minutes, if the vendor allows — SHOULD);
- pay-on-delivery jobs: rider's cash balance below the limit.

Order of offers: nearest first (road ETA when available, else straight line), then highest rating, then fewest jobs today (fairness).

Offer mechanics:
- Offer one rider at a time (or a small batch, setting `offers_per_round`, default 1).
- Offer contains: vendor name and area, pickup distance from rider, drop-off area (not exact address until accepted), estimated distance, rider earning for the job (delivery share + tip), pay-on-delivery amount to collect, package type for parcels.
- Delivered by high-priority FCM data message + Supabase Realtime channel `rider:{id}`; the rider app rings and shows a countdown.
- Rider accepts → server checks the order is still unassigned (row lock) → assign rider, withdraw other open offers. First accept wins; others see "Job taken".
- Decline or no answer within `offer_ttl_seconds` (default 30) → next candidate.
- If nobody accepts after a full round, widen the radius by a step (setting) and retry; escalate to admin after 10 minutes ([04](04-order-lifecycle.md) §4.6).

Manual assignment: admin (any order) or vendor (own riders) can assign a specific rider; the rider gets a notification and must accept (SHOULD) — or admin can force-assign (audited).

Reassignment: admin can unassign a rider before pickup (reason required); the order goes back to matching.

## 7.3 Delivery flow in the rider app

1. **Accepted** → navigate to pickup (open Google Maps / Waze with coordinates).
2. **At pickup** → "Arrived" (SHOULD; notifies vendor). Vendor marks ready if not already.
3. **Picked up** → rider confirms items (and shows pickup code to vendor — SHOULD). Status `picked_up`; customer gets "Your order is on the way" + live tracking.
4. **Navigate to drop-off** → full address, landmark, customer phone (masked/call button), notes.
5. **Handover** → customer tells the rider the 4-digit handover code; rider enters it. Correct code → `delivered`. Three wrong attempts → rider must call support. The code is required on **every** delivery (owner decision 2026-11-01): there is no photo or signature fallback. If the customer cannot give the code, the rider contacts support, which verifies the customer by phone and can complete the delivery as an audited admin override.
6. **Failed delivery** → rider picks a reason, takes a photo, and must have tried to call the customer (call attempt logged). Admin reviews.
- Parcels: each stop has its own code (sent to that stop's contact by SMS) when the package type requires stop verification.

## 7.4 Live tracking (MUST)

- Rider app sends location every 10 s while online and on a job (every 30 s while online and idle), or after 25 m of movement — whichever first. Low-data mode doubles the intervals.
- Location goes to Laravel (authoritative, stored in `rider_locations`) **and** is broadcast on Supabase Realtime channel `order:{uuid}` for the customer and vendor, authorised by a Laravel-minted JWT scoped to that order.
- Customer sees the rider's marker, ETA, rider name, photo, vehicle, rating, and a call button.
- Tracking ends at delivery; the customer channel is closed.
- Android rider app uses a foreground service of type `location` with a persistent notification while online.

## 7.5 Rider availability

- Online/offline toggle. Going online requires location permission ("Allow all the time" on Android for background) and an approved account.
- Auto-offline after 10 minutes without location updates.
- Riders can't go offline while holding an active job (they can, but must hand it back via support).

## 7.6 Vendor's own riders (in scope — Q8 confirmed)

All three rider types are in scope (owner decision 2026-11-01): **WeGoDeliva-managed** (recruited and supervised by WeGoDeliva ops; may get guaranteed minimums or shifts — LATER), **independent** (self-signed-up freelancers, paid per job), and **vendor-owned** (below). Matching treats managed and independent riders the same; admins can prioritise managed riders per zone (setting).


- Vendor owner invites riders (by phone); they use the same rider app and see only that vendor's jobs.
- Earnings for vendor-owned riders: the delivery fee is credited to the vendor (minus the 3% delivery commission); the vendor pays its riders off-platform.
