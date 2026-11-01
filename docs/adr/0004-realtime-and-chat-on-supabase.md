# 0004. Realtime and chat on Supabase Realtime

Date: 2026-11-01 · Status: Accepted

## Context
The apps need live rider location, order status updates, rider job offers and in-app chat (in scope at launch). Firestore is not allowed (spec 01 O6).

## Decision
- Laravel mints **short-lived JWTs**, signed with the Supabase JWT secret, carrying the user id, role and allowed channels: `order:{uuid}`, `rider:{id}`, `vendor:{id}`, `conversation:{uuid}`.
- **Writes go through Laravel**: location updates, chat messages and status changes are validated, rate-limited and stored in Postgres, then broadcast on the channel. Clients only subscribe.
- RLS policies on realtime-exposed tables check the JWT claims. The service-role key exists only on the server.
- FCM high-priority data messages remain the wake-up path when an app is in the background; Realtime is used while a screen is open.

## Consequences
- No client can forge a location or a message for someone else.
- Chat history lives in Postgres, so it can be moderated, exported and used in disputes.
- Every broadcast costs a Laravel round-trip, so the location endpoint accepts batches.
