# 0002. Money as integer kobo with ledgers

Date: 2026-11-01 · Status: Accepted

## Context
The old system stored money as floating-point numbers and changed balances with read-modify-write, so rounding errors and lost updates were possible (spec legacy-notes D8).

## Decision
- All amounts are `bigint` **kobo**; rates are `int` **basis points**. No floats in money code. PHP uses a small `Money` value object; Dart and TypeScript use integers plus formatting helpers.
- Rounding is half-up to the kobo, at the point each component is computed (spec 05).
- Every balance (customer wallet, vendor/rider earnings, rider cash owed) is a **cached sum of an append-only ledger**. It only changes inside a DB transaction that holds a `SELECT … FOR UPDATE` lock on the balance row, and each ledger row stores `balance_after`.
- Every money operation has a unique reference (idempotency key) with a unique index.

## Consequences
- Balances can be rebuilt from the ledger, which makes reconciliation possible (ADR 0007).
- API responses carry `amount_kobo` plus a formatted string.
- Pricing, payment, wallet and order-state logic need 100% test coverage (CLAUDE.md §4).
