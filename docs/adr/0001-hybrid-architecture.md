# 0001. Hybrid architecture

Date: 2026-11-01 · Status: Accepted

## Context
WeGoDeliva needs one source of truth for money and orders, live tracking, three mobile apps and a web admin, run by a small team. CLAUDE.md §2 fixes the stack.

## Decision
- **Laravel 12 (PHP 8.3+)** owns users, auth (Sanctum), orders, payments, wallets, earnings, business rules, queues and the scheduler. It is the only writer of money-related tables.
- **Supabase Postgres** is Laravel's database (pooled connection for web traffic, direct connection for migrations). **Supabase Realtime** carries live events. **Supabase Storage** holds files in private buckets behind signed URLs.
- **Next.js (App Router, TypeScript)** on Vercel for the admin panel and vendor portal. It calls the Laravel API and never touches the database directly.
- **Flutter** apps (customer, vendor, rider) share `core` and `ui` packages in a Melos workspace.
- **FCM** only, for push.

## Consequences
- Business logic lives in one place (Laravel), so feature tests can cover it.
- Supabase tables need Row Level Security even though only Laravel writes, because the anon key ships inside the apps for Realtime.
- Laravel can't run on Vercel or shared cPanel; it needs Laravel Cloud or a VPS (decided during staging setup).
