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
- **Closed loop** (owner decision 2026-11-01): wallet money can only be spent on WeGoDeliva. **No withdrawal to a bank account** and no transfers between users. The only ways money leaves a wallet are order payments and admin adjustments. The terms of service must say so.
- **KYC tiers following CBN's three-tier structure** (owner decision). Limits are settings. The figures below are the ones most consistently reported from CBN's 2017 mobile-money review (circular BPS/DIR/GEN/CIR/04/007). Some 2026 sources quote ₦30,000 a day for Tier 1, so **confirm against the CBN circular itself**. Since CBN's December 2023 circular, Tier 2 and 3 need **both BVN and NIN**.

  | Tier | Verification needed | Daily top-up limit | Max wallet balance |
  | --- | --- | --- | --- |
  | 1 (default) | Verified phone (OTP) + name + verified email | ₦50,000 | ₦300,000 |
  | 2 | Tier 1 + **BVN and NIN** verified through a KYC provider | ₦200,000 | ₦500,000 |
  | 3 | Tier 2 + government ID document + verified address | ₦5,000,000 | No cap |

  A top-up that would exceed the daily limit or the balance cap is refused, with a message explaining how to upgrade. Refunds and referral rewards may exceed the daily top-up limit but not the balance cap; any excess is refunded to the original payment method. Launch scope: Tiers 1 and 2. Tier 3 is LATER.

### 6.4.1 Regulatory position and recommendation (research 2026-11-01, R4)

What the research found (see sources in the owner report). This is not legal advice:
- In Nigeria, **issuing wallets that hold customer funds is a regulated activity**. Sources agree that a Payment Solution Service Provider (PSSP) licence does **not** allow holding customer funds or issuing wallets. Wallets fall under the **Mobile Money Operator (MMO)** licence, reported at about ₦2 billion minimum capital, or are run by a bank or MMO partner.
- No source found a Nigerian equivalent of the EU/UK "limited network" exemption for closed-loop wallets. **Being closed-loop does not, on the evidence found, take a prepaid top-up wallet outside CBN rules.**
- Collecting payments for vendors and paying them out after commission (the marketplace model) is common, and normally runs on licensed processors' settlement features (Paystack/Flutterwave). That is a different question from customers storing their own money with WeGoDeliva.

**Recommendation, needs owner approval because it narrows the earlier decision:**
1. **Launch without cash top-ups.** The wallet becomes **"WeGoDeliva Credit"**, holding only (a) refunds the customer chooses to take as credit (they can always choose a refund to their card or bank instead) and (b) referral rewards and promotional credit. Credit is closed-loop, can't be withdrawn or transferred, and is spent at checkout like today. No customer deposits means far lower regulatory exposure. The KYC tiers stay designed but switched off.
2. **Add cash top-ups later** only through a **licensed partner**: a bank or MMO wallet-as-a-service provider, where each customer wallet is a sub-account the partner holds and the partner does KYC. Our ledger mirrors the partner's balances. This keeps the top-up UX and the CBN tier limits (the partner enforces them) without WeGoDeliva needing an MMO licence.
3. **Vendor and rider earnings**: before launch, ask the Paystack/Flutterwave account managers whether holding earnings until a payout request (with commission deducted at withdrawal) is acceptable in a WeGoDeliva merchant balance. The alternative is the gateways' split-payment/subaccount features, which pay vendors automatically. That would change "commission at withdrawal" to "commission at payment".
4. Get a short written opinion from a Nigerian fintech lawyer on 1–3 before go-live. Budget for it.

The ledger, KYC and limit design in this section stays as written. Only the **top-up feature flag** is off at launch, pending the owner's decision.

- **Top-up** (when enabled): amount ≥ minimum (setting, default ₦100) and within the tier limits. Creates a pending top-up + gateway payment; credited only after server verification as in 6.2. Pending top-ups expire after 15 minutes.
- **Paying for an order** with the wallet: debit `total` in the same transaction that creates the order.
- **History**: paginated list of transactions with kind, amount, date, related order.
- Admin manual credit/debit: requires a reason, written to the audit log, shown to the user as "Adjustment".

## 6.5 Pay on delivery

- Rider sees "Collect ₦X" on the job.
- At handover the rider enters the handover code and confirms "Cash collected" or "Transfer received" (for transfers, SHOULD show WeGoDeliva's virtual account / rider's account details).
- Order becomes paid; the amount is added to the rider's cash balance ([05](05-pricing-fees-commission.md) §5.8).
- Pay on delivery is allowed for **all vendors and zones**, for orders up to **₦20,000** total (owner decision; setting). Above that, the option is shown disabled with the reason.
- Riders whose cash balance reaches **₦20,000** (setting) cannot accept new pay-on-delivery jobs until they remit; they can still take prepaid jobs. (Replaces the old "rider must hold wallet balance" rule — Q11.)
- Remitting: the rider transfers to WeGoDeliva's collection account with their rider reference (a dedicated virtual account per rider via Paystack/Flutterwave — SHOULD), or the cash owed is netted off their next withdrawal (§6.7).

## 6.6 Refunds

Triggered when a **paid** order is cancelled or fails, or when an admin issues a partial refund (missing item, etc.).
- Destination: **customer wallet**, instant (auto-refund setting on). Admin can instead refund to the original card/account via the gateway refund API. Because wallets are closed-loop, a customer who asks for their money back (rather than wallet credit) gets it via the gateway refund, not a wallet withdrawal.
- Refund amount = amount actually paid (or the partial amount set by admin, ≤ paid amount − already refunded).
- Exactly one full refund per order; refunds are idempotent and ledgered.
- Pay-on-delivery orders that were never paid have nothing to refund.

### Who bears the cost (team recommendation, adopted per owner's "advise on best practice")

Principle: the customer is made whole whenever they are not at fault; the party at fault absorbs the cost; WeGoDeliva absorbs platform failures. Every cancellation/failure records a `fault` (customer / vendor / rider / platform / none) chosen from preset reasons, editable by an admin with a note.

| Situation | Customer gets | Vendor | Rider | Platform |
| --- | --- | --- | --- | --- |
| Customer cancels before vendor accepts | Full refund | Nothing owed | — | — |
| Customer cancels after accept (via support, before pickup) | Refund minus a cancellation fee (setting, default: 50% of items for food already being prepared, ₦0 for non-perishables) | Paid the cancellation fee (minus commission) | — | — |
| Vendor rejects, times out, or cancels after accepting | Full refund | No earnings; repeated cases flagged (acceptance-rate score, possible suspension) | — | Absorbs gateway fees |
| Missing / wrong / damaged item (vendor fault) | Partial or full refund of affected items (+ delivery fee if the order was unusable) | Earnings for refunded items reversed | — | — |
| No rider found / platform or payment-system failure | Full refund | Paid for items already prepared (platform cost) | — | Absorbs |
| Rider fault (lost, damaged in transit, rider no-show after pickup) | Full refund | Paid in full | Delivery earning reversed; charged the item cost up to a cap (setting) after admin review; repeated cases → suspension | Absorbs the rest |
| Customer unreachable / wrong address / refuses delivery (customer fault) | Items refunded **only if** the vendor can resell them (non-perishable, returned); delivery fee **not** refunded | Paid for perishable items | Paid the delivery fee (+ return trip fee if set) | — |
| Pharmacy declines prescription | Full refund | — | — | — |

- Reversals of vendor/rider earnings are ledger debits (never edits of past credits); if the balance is insufficient, the debt carries over and is netted at the next withdrawal.
- Gateway transaction fees on refunded payments are absorbed by WeGoDeliva (not passed to customers).
- Disputes: customer can contest via the order's "Report a problem"; admin decides within 48 h (SLA setting).

## 6.7 Earnings and payouts

- Vendor owners and riders see gross earnings, commission due, cash owed (riders), available to withdraw (see [05](05-pricing-fees-commission.md) §5.8), a ledger, and downloadable statements (SHOULD).
- **Payout accounts**: bank + account number, verified with the gateway's account-resolve API; the verified account name must reasonably match the vendor/rider name (admin reviews mismatches).
- **Payouts on request** (owner decision): amount ≥ minimum (setting, ₦1,000) and ≤ available to withdraw. Creates `requested`.
- Admin approves/rejects (auto-approval under a threshold for verified accounts — SHOULD). On approval, in one transaction: settle accrued commission and (riders) cash owed, debit the payout amount (held), then send through the **Paystack or Flutterwave Transfers API** (setting chooses the gateway; WeGoDeliva keeps a funded transfer balance). Transfer webhooks move payouts to `paid` / `failed`; failure or rejection re-credits the payout amount (commission settlement stays).
- Transfer fees: paid by WeGoDeliva (default) or deducted from the payout (setting).

## 6.8 Referrals (owner decision 2026-11-01)

- Each user has a referral code and a share link (`https://wegodeliva.com/r/{code}` → App Link / Universal Link; falls back to store page).
- New user signs up with a code (typed or from the link) → `referrals` row `pending`. An invalid code shows a message but does not block sign-up.
- **Reward**: 5% of the referred user's **first purchase only**, capped at **₦5,000**, credited to the **referrer's wallet** after that purchase is complete (order `delivered`, or service booking `completed`). If 5% exceeds ₦5,000, the referrer gets ₦5,000.
- "Purchase" amount = what the new customer paid for goods/services: subtotal − discount (excluding delivery fee, service fees, VAT and tip) — so the reward is not inflated by fees. If the first order is cancelled/refunded, the next completed order counts.
- No reward for the new user (not requested). Funded by WeGoDeliva.
- Anti-abuse: one referral per phone number, email and device; self-referral blocked; the reward is clawed back (wallet debit) if the qualifying order is refunded within 7 days; admin can void.

## 6.9 Loyalty points (LATER — Q2; not built at launch)

Old behaviour for reference: points = order total × rate on delivered+paid orders; points could be converted to wallet balance at a fixed rate when the feature was switched on.
