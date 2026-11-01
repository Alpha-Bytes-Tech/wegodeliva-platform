# 4. Order lifecycle

## 4.1 What the old system did (summary)

- One order status at a time, taken from a status-history list: `scheduled`, `pending`, `preparing`, `ready`, `enroute`, `delivered`, `failed`, `cancelled`.
- A separate payment status: `pending`, `request` (vendor asks customer to pay an amended amount — used for prescriptions), `review` (prescription waiting for pharmacy), `failed`, `cancelled`, `successful`.
- New orders were `scheduled` if the requested time was more than a configurable number of hours ahead (default 2), otherwise `pending`.
- If the vendor had **auto-accept** on and the order was paid (or cash), it moved straight to `preparing`.
- For parcel operators with auto-dispatch on, paid/cash parcel orders moved straight to `ready` so riders could be offered the job.
- Riders were offered orders that were `preparing` or `ready`; accepting set the rider on the order.
- Unpaid online orders were cancelled after a timeout (default 10 min); `pending` orders not accepted by the vendor were auto-cancelled after a timeout (default 30 min); stale orders from previous days were cancelled every 6 hours.
- On `delivered`, cash orders became paid, earnings and commission were calculated, referral and loyalty rewards were granted, and an email was sent.
- On `cancelled`/`failed`, a paid non-cash order was refunded to the wallet (if auto-refund was on) and stock was returned.

## 4.2 New state machine (MUST)

Statuses:

| Status | Meaning |
| --- | --- |
| `awaiting_payment` | Online-payment order created; waiting for verified payment. Not shown to the vendor. |
| `awaiting_review` | Pharmacy order with a prescription; pharmacy must confirm items and price. |
| `scheduled` | Paid/confirmed order for a future time; becomes `placed` at (scheduled time − prep time − buffer). |
| `placed` | Waiting for the vendor to accept. Vendor's phone rings. |
| `accepted` | Vendor accepted and gave a prep time (and is preparing). Rider matching starts here (configurable: at accept, or N minutes before ready). |
| `ready` | Ready for pickup (or for the customer, if self-pickup). |
| `picked_up` | Rider has collected it (pickup code checked, SHOULD). Live tracking active. |
| `delivered` | Handover code verified — **required on every delivery** (owner decision 2026-11-01). Final. |
| `cancelled` | Stopped before pickup. Final. |
| `failed` | Could not be delivered after pickup (customer unreachable, address wrong…). Final; needs admin review for returns/refunds. |

Allowed transitions and who may trigger them:

| From → To | Customer | Vendor | Rider | System | Admin |
| --- | --- | --- | --- | --- | --- |
| awaiting_payment → placed / scheduled | | | | ✔ payment verified | ✔ |
| awaiting_payment → cancelled | ✔ | | | ✔ payment timeout | ✔ |
| awaiting_review → awaiting_payment / placed | | ✔ confirms items & price | | | ✔ |
| awaiting_review → cancelled | ✔ | ✔ (reason) | | ✔ review timeout | ✔ |
| scheduled → placed | | | | ✔ release time | ✔ |
| scheduled → cancelled | ✔ (until release) | ✔ (reason) | | | ✔ |
| placed → accepted | | ✔ (+ prep time) | | ✔ auto-accept | ✔ |
| placed → cancelled | ✔ | ✔ reject (reason) | | ✔ vendor timeout | ✔ |
| accepted → ready | | ✔ | | | ✔ |
| accepted → cancelled | | ✔ (reason; counts against vendor) | | | ✔ |
| ready → picked_up | | ✔ (self-pickup: customer collected) | ✔ | | ✔ |
| ready → cancelled | | | | ✔ no rider found after max wait | ✔ |
| picked_up → delivered | | | ✔ with handover code | | ✔ |
| picked_up → failed | | | ✔ (reason + photo) | | ✔ |
| any non-final → cancelled/failed | | | | | ✔ (reason, audited) |

Rules:
- Any transition not in the table is rejected with a clear error. Final states never change (admin corrections are made with refunds/adjustments, not by reopening).
- Every transition writes `order_status_history` and triggers the notifications in [08](08-notifications.md).
- Self-pickup orders skip rider matching; the customer shows their handover code to the vendor, and `ready → delivered` is allowed for the vendor with the code.
- A customer may cancel only in `awaiting_payment`, `awaiting_review`, `scheduled`, `placed`. After the vendor accepts, the customer must contact support. (The old system made the "non-cancellable statuses" an admin setting; keep it as a setting with this default.)

## 4.3 Payment status (separate field)

`unpaid` → `pending` (gateway checkout started) → `paid` | `failed`; later `refunded` / `partially_refunded`.
Pay-on-delivery orders stay `unpaid` until the rider confirms collection at delivery, then become `paid` (with collection method cash / transfer recorded).
Order creation for online methods results in `awaiting_payment`; the order moves on only when the **server** has verified the payment ([06](06-payments-wallet-payouts.md)). The client can never set payment status (old defect D4).

## 4.4 Placing an order (checkout)

1. App asks for a **quote**: vendor, items with options, address or pickup, coupon, tip, scheduled time. Server computes the full price breakdown ([05](05-pricing-fees-commission.md)) and returns it with a `quote_id` valid for 10 minutes, bound to the user.
2. App places the order with `quote_id`, payment method and note. Server re-validates: vendor active and open (or schedulable), items active and in stock, minimum/maximum order amount, address inside a zone the vendor serves, coupon still valid for this user. Server **recomputes** totals; if they differ from the quote, it returns the new quote for confirmation instead of placing.
3. In one DB transaction with row locks: create order + items + fees snapshot, reserve stock (decrement; fail if it would go negative), record coupon redemption, and for wallet payments debit the wallet.
4. Return order code and, for online payment, gateway checkout details.
- Idempotency: the place-order request carries an `Idempotency-Key` header so a double tap does not create two orders.
- Customer must have a **verified email** and a phone number on file before placing an order. Riders and vendors need the phone to reach the customer; it is unverified at launch.
- A customer whose wallet is negative (owes money) cannot place orders until they top up (old behaviour kept; rare in the new design).

## 4.5 Scheduling rules

- Scheduled orders allowed only if the vendor allows them.
- Earliest slot: now + `min_schedule_lead_minutes` (default 120). Latest: `max_schedule_days` ahead (default 3).
- Slot must fall inside vendor opening hours.

## 4.6 Timeouts and background jobs (MUST)

| Job | Frequency | Rule (defaults are settings) |
| --- | --- | --- |
| Cancel unpaid online orders | every minute | `awaiting_payment` older than 15 min → re-verify with gateway once, then `cancelled` (reason "payment timeout"), release stock |
| Expire pending wallet top-ups | every minute | pending top-up older than 15 min → re-verify, then `failed` |
| Vendor acceptance timeout | every minute | `placed` for more than 10 min → alert admin at 5 min; cancel + full refund at 10 min |
| Prescription review timeout | every 5 min | `awaiting_review` more than 60 min → cancel, notify |
| Release scheduled orders | every minute | `scheduled` → `placed` at release time |
| Expire rider offers | every 15 s (queue delay) | offer not answered within `offer_ttl_seconds` (default 30) → `expired`, offer next rider |
| No-rider escalation | every minute | `ready` with no rider after 10 min → alert admin; after 30 min → admin decides |
| Vendor auto open/close | every minute | Set `open_state` from vendor hours unless manually overridden |
| Stale order sweep | hourly | non-final orders older than 24 h → alert admin (do **not** auto-cancel picked-up orders) |
| Rider location cleanup | daily | delete location samples older than retention |

## 4.7 After delivery

On `delivered`, in one transaction and exactly once per order (idempotent):
- mark pay-on-delivery orders paid and add the collected amount to the rider's cash balance;
- compute and store commission ([05](05-pricing-fees-commission.md) §5.8), credit vendor and rider earnings;
- confirm a pending referral reward if this is the referred user's first delivered order;
- send receipt email/notification; prompt the customer to rate.

On `cancelled` or `failed`:
- return reserved stock;
- record the `fault` and refund per [06](06-payments-wallet-payouts.md) §6.6;
- void coupon redemption so the customer can reuse it (SHOULD).

## 4.8 Service bookings (in scope from 2026-11-01)

A service booking is an order of type `service` with one service line instead of product lines, no stock, and usually no rider.

Extra fields: `service_id`, `units` (hours/days/months or 1 for fixed), `starts_at`, `ends_at`, `location` (customer address or provider location), chosen options.

Statuses:

| Status | Meaning |
| --- | --- |
| `awaiting_payment` | As for goods (online payment). |
| `requested` | Waiting for the provider to accept the slot. |
| `confirmed` | Provider accepted. Reminder notifications 24 h and 1 h before. |
| `in_progress` | Provider started the service (at the slot time). The customer gives the provider the **handover code** to start — proves the provider showed up. |
| `completed` | Provider marked done; the customer confirms or it auto-confirms after 24 h (setting). Triggers earnings, commission and referral reward. |
| `cancelled` | Before start, with the same fault rules as goods ([06](06-payments-wallet-payouts.md) §6.6). Free customer cancellation until X hours before start (setting, default 12 h); after that a late-cancellation fee (setting). |
| `disputed` | Customer reported a problem within 24 h of completion; admin resolves (refund, partial refund, or release). |

- Provider capacity: each service has a max number of simultaneous bookings (default 1); slots are offered only inside the vendor's hours and not clashing with confirmed bookings.
- Pay on delivery for services = pay the provider at completion (same ₦20,000 limit); the provider's cash collected is posted as owed by the vendor (netted at withdrawal).
- Provider acceptance timeout: 2 h, or 30 min for bookings starting within 3 h (settings).
