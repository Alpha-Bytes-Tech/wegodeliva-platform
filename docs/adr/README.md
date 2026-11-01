# Architecture decision records

One file per significant decision: context, decision, consequences. Status is Proposed, Accepted, Superseded by NNNN, or Deprecated. New ADRs take the next number. Accepted ADRs are never edited except to mark them superseded.

| # | Title | Status |
| --- | --- | --- |
| [0001](0001-hybrid-architecture.md) | Hybrid architecture: Laravel core, Supabase data/realtime, Next.js web, Flutter apps | Accepted |
| [0002](0002-money-in-kobo.md) | Money as integer kobo, ledgers for every balance | Accepted |
| [0003](0003-order-state-machines.md) | Explicit order and booking state machines | Accepted |
| [0004](0004-realtime-and-chat-on-supabase.md) | Realtime tracking and chat on Supabase Realtime, authorised by Laravel JWTs | Accepted |
| [0005](0005-rider-dispatch.md) | Sequential rider offers with server-side locking | Accepted |
| [0006](0006-commission-at-withdrawal.md) | Earnings credited gross; commission and its VAT settled at withdrawal | Accepted |
| [0007](0007-funds-held-at-gateways.md) | All funds held at Paystack/Flutterwave; daily ledger reconciliation | Accepted |
| [0008](0008-email-otp.md) | One-time codes by email, no SMS at launch | Accepted |
