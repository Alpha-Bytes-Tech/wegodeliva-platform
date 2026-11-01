# 0003. Explicit order and booking state machines

Date: 2026-11-01 · Status: Accepted

## Context
The old system let clients set order fields and statuses directly (legacy-notes D4, D15) and ran side effects in model observers on every save (D17).

## Decision
- Orders (spec 04 §4.2) and service bookings (spec 04 §4.8) each have a declared transition table: from-state, to-state, allowed actors, guard conditions.
- Transitions run only through **action classes** (e.g. `AcceptOrder`, `MarkPickedUp`), never through generic updates. Each action checks the policy and guards, writes `order_status_history`, and dispatches domain events after commit.
- Side effects (notifications, earnings, refunds, referral rewards) are **queued listeners** on those events. Each is idempotent and keyed by order id.
- Payment status is a separate field that only the payment-verification code can change.

## Consequences
- Every allowed and forbidden transition gets a test.
- Admin "force status" is an explicit, audited action, not a backdoor update.
