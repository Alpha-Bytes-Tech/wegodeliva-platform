# 6. Payments, wallet, refunds, payouts, referrals

## 6.1 Payment methods

| Method | Behaviour |
| --- | --- |
| Paystack | Card, bank transfer, USSD through Paystack checkout (in-app SDK or hosted page). |
| Flutterwave | Same, through Flutterwave checkout. Admin can switch either gateway off. |
| Wallet | Paid instantly from the customer's WeGoDeliva wallet if the balance covers the total. |
| Pay on delivery | Customer pays the rider in cash or by transfer at handover. Admin can disable it per vendor, per zone, or above an order amount (SHOULD, to limit fraud). |

Old system: gateway secret keys were stored in the database and editable in the admin panel. **New:** keys live only in environment secrets; the admin panel can enable/disable a method and edit its display text.

## 6.2 Online payment for an order (MUST)

1. Order is created in `awaiting_payment`. Server creates a `payments` row with **our own unique reference**, `amount_kobo = order.total_kobo`, currency NGN, and initialises the transaction with the gateway (amount, reference, customer email/phone, callback URL, metadata `order_uuid`).
2. App opens the gateway checkout. On return, the app calls "verify payment" — this only *triggers* server verification, it never asserts success.
3. Server verifies by calling the gateway's verify endpoint with **its** secret key and checks **all** of: status = success, **reference matches**, **amount == expected amount in kobo**, **currency == NGN**, and the transaction has not already been applied. Only then: payment → `succeeded`, order payment_status → `paid`, order status → `placed`/`scheduled`.
4. The gateway **webhook** does the same verification independently (whichever arrives first wins; the second is a no-op).
5. Mismatched amount/currency → payment flagged `failed` with reason "amount mismatch", admin alerted, order stays unpaid. No partial acceptance.
6. If verification can't reach the gateway, retry with back-off; the timeout job re-verifies once before cancelling.

The old system skipped the amount, currency and reference checks and accepted webhooks without signatures (defects D2, D3).

## 6.3 Webhooks (MUST)

- Paystack: verify `x-paystack-signature` (HMAC-SHA512 of the raw body with the secret key). Flutterwave: verify `verif-hash` header against the configured secret hash.
- Reject bad signatures with 401 and log them.
- Store every event in `webhook_events` with a unique constraint on the gateway event/reference → replayed events are ignored.
- Respond 200 quickly; process on a queue.
- Supported events: charge success / failed, transfer success / failed / reversed (for payouts), refund processed.
- Allow-list gateway IPs as defence in depth (SHOULD).

## 6.4 Wallet

- Every user has one wallet (created at sign-up). Currency NGN.
- **Balance changes only through the ledger**: each change creates a `wallet_transactions` row with `balance_after`, inside a DB transaction holding a row lock on the wallet. Wallet balance never goes below zero except through an explicit admin adjustment.
- **Top-up**: amount ≥ minimum (setting, default ₦100) and ≤ maximum (setting, e.g. ₦200,000, KYC-dependent — open question). Creates a pending top-up + gateway payment; credited only after server verification as in 6.2. Pending top-ups expire after 15 minutes.
- **Paying for an order** with the wallet: debit `total` in the same transaction that creates the order. (The old system had a setting to leave the delivery fee to be paid in cash; dropped unless the owner wants it.)
- **History**: paginated list of transactions with kind, amount, date, related order.
- **Wallet transfers between users**: proposed **out of scope** (Q3).
- Admin manual credit/debit: requires a reason, written to the audit log, shown to the user as "Adjustment".

## 6.5 Pay on delivery

- Rider sees "Collect ₦X" on the job.
- At handover the rider enters the handover code and confirms "Cash collected" or "Transfer received" (for transfers, SHOULD show WeGoDeliva's virtual account / rider's account details).
- Order becomes paid; the amount is added to the rider's cash balance ([05](05-pricing-fees-commission.md) §5.8).
- Riders with cash balance above a limit (setting) cannot accept new pay-on-delivery jobs until they remit. (Replaces the old "rider must hold wallet balance" rule — Q11.)

## 6.6 Refunds

Triggered when a **paid** order is cancelled or fails, or when an admin issues a partial refund (missing item, etc.).
- Default destination: **customer wallet**, instant (old behaviour; controlled by an "auto-refund" setting). Optional: back to the original card via gateway refund API (admin action).
- Refund amount = amount actually paid (or the partial amount set by admin, ≤ paid amount − already refunded).
- Exactly one full refund per order; refunds are idempotent and ledgered.
- Pay-on-delivery orders that were never paid have nothing to refund.
- Who bears the cost (vendor fault vs customer fault vs rider fault) — open question; affects earnings reversal.

## 6.7 Earnings and payouts

- Vendor owners and riders see their earnings balance, a ledger of credits (per order) and debits (payouts, COD settlements, adjustments), and downloadable statements (SHOULD).
- **Payout accounts**: bank + account number, verified with the gateway's account-resolve API; the verified account name must reasonably match the vendor/rider name (admin reviews mismatches).
- **Payout request**: amount ≤ available balance − outstanding cash balance (riders); minimum amount setting. Creates `requested`.
- Admin approves/rejects. Approved payouts are sent via Paystack/Flutterwave **Transfers** API (or marked paid manually after an offline transfer — SHOULD support both). Balance is debited when the payout is **approved** (held), and re-credited if it fails or is rejected.
- Transfer webhooks move payouts to `paid` / `failed`.
- Optional automatic weekly payouts (LATER).

## 6.8 Referrals

- Each user has a referral code and a share link (`https://wegodeliva.com/r/{code}` → App Link / Universal Link; falls back to store page).
- New user signs up with a code (typed or from the link) → `referrals` row `pending`. Invalid codes are rejected with a clear message (old behaviour) — SHOULD instead ignore silently so sign-up isn't blocked.
- When the referred user's **first order is delivered**, the referrer's wallet is credited the reward amount (setting) and the referral becomes `rewarded`. Reward for the new user too (SHOULD — owner decides).
- Anti-abuse: one referral per phone number and device; self-referral blocked; admin can void.

## 6.9 Loyalty points (LATER, if confirmed — Q2)

Old behaviour for reference: points = order total × rate on delivered+paid orders; points could be converted to wallet balance at a fixed rate when the feature was switched on.
