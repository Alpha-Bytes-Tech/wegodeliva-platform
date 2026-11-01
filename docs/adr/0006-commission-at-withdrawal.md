# 0006. Commission settled at withdrawal

Date: 2026-11-01 · Status: Accepted

## Context
Owner decisions: 5% vendor commission and 3% of the delivery fee, deducted when the vendor or rider withdraws. WeGoDeliva charges 7.5% VAT on its commission; vendors remit VAT on their own goods (spec 05 §5.6, §5.8).

## Decision
- At delivery, one idempotent job credits **gross** earnings (including the vendor's pass-through VAT) and records **accrued** commission and VAT on commission as pending debits in the same ledger.
- "Available to withdraw" = gross − accrued commission − VAT on commission − cash owed (riders) − pending payouts.
- Payout approval settles all accruals and debits the payout in one transaction. The payout statement is the tax invoice for the commission.
- Refund and fault reversals are new ledger debits, never edits.

## Consequences
- Vendors see their real takings at once, and the platform can't overpay.
- If a vendor never withdraws, the commission stays accrued and shows on the finance dashboard.
