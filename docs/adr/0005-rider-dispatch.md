# 0005. Rider dispatch

Date: 2026-11-01 · Status: Accepted

## Context
Spec 07 §7.2 requires finding nearby online riders, offering jobs, letting the first accept win, then widening the radius and escalating to an admin. The old system pushed offers to many riders at once and raced them through a document store.

## Decision
- Rider positions are stored in Postgres with PostGIS (`geography(Point)`, GiST index). Candidates come from `ST_DWithin` plus the eligibility filters in spec 07.
- A queued **dispatch job** per order offers the job to one rider at a time (batch size configurable), each offer with a time limit. Expiry is handled by a delayed job, not polling.
- Accepting runs in a transaction with `SELECT … FOR UPDATE` on the order. If a rider is already assigned, the request returns 409 "Job taken".
- The offer is delivered by FCM data message plus Realtime on `rider:{id}`.

## Consequences
- An order never has more than one rider, even under concurrency.
- PostGIS must be enabled in Supabase (it is supported).
