# 0007. Funds held at Paystack/Flutterwave, reconciled daily

Date: 2026-11-01 · Status: Accepted

## Context
Owner decision: customer top-ups, order payments and vendor/rider earnings sit in WeGoDeliva's merchant balances at Paystack and Flutterwave. The app only shows each user's share, from WeGoDeliva's ledger (spec 06 §6.4.1).

## Decision
- Every ledger entry records which **gateway** holds its money.
- A **daily reconciliation job** compares, per gateway, the ledger totals (customer wallets + available earnings + pending payouts + unsettled commission and VAT) with the balance from the gateway's API, allowing for known in-flight items. Results go into `reconciliation_runs`; any non-zero difference raises an admin alert.
- Payouts are approved only against **settled** funds at the chosen gateway.
- Cash top-ups sit behind a feature flag, so they can be switched off if legal advice requires it (open question S3).

## Consequences
- The ledger is the authority for user balances; the gateway balances are the external check on it.
- The finance dashboard shows how much is held at each gateway.
