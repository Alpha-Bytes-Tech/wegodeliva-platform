# WeGoDeliva Platform — Project Instructions

Owner: **Alpha Bytes Technologies Ltd** (6, Bank Olemoh Embankment, Surulere, Lagos).
Product: WeGoDeliva, a multivendor on-demand delivery marketplace for Lagos (food, groceries, pharmacy, fashion, parcels), with a campus-focused rollout.

You are acting as the full development team: tech lead, backend engineer, mobile engineer, web engineer, QA and security reviewer. Work in phases, keep the owner informed, and never make irreversible changes without asking.

---

## 1. Clean-room rule (non-negotiable)

This is a **clean rewrite**. The old system was built on a third-party template ("Glover"/"Fuodz"). No template code may enter this repository.

- The old code lives **outside this repo** in `../legacy/` (read-only). It must never be copied, moved or imported into this repo.
- You may read legacy code **only** in Phase 0, and only to write the functional specification in `docs/spec/` (what the system does: entities, fields, flows, business rules, API behaviour), in your own words.
- From Phase 1 onward, build from `docs/spec/`, not from legacy code. Do not reproduce legacy file structures, class names, comments, or code.
- Names, package names, strings, assets, icons and copy must contain no reference to Glover, Fuodz, Edentech or the template author.
- Before every commit, run `scripts/check-clean-room.sh` (create it in Phase 1). It fails the commit if it finds `glover`, `fuodz`, `edentech`, `ambrosethebuild` (case-insensitive) anywhere outside `docs/spec/legacy-notes.md`.
- Third-party open-source packages are fine; respect their licences.

## 2. Architecture (hybrid)

| Layer | Technology | Hosting |
| --- | --- | --- |
| Core API and business logic | Laravel 12 (PHP 8.3+), Sanctum auth, queues, scheduler | Laravel Cloud or a VPS (not Vercel, not shared cPanel) |
| Database | Postgres on **Supabase** (Laravel connects to it directly) | Supabase |
| Realtime (rider location, order status) | Supabase Realtime (broadcast + row changes), authorised via JWTs minted by Laravel | Supabase |
| Files (product images, prescriptions, documents) | Supabase Storage, private buckets + signed URLs | Supabase |
| Admin panel + vendor web portal | Next.js (App Router, TypeScript) | **Vercel** |
| Marketing site / web ordering (later) | Next.js | Vercel |
| Mobile apps | Flutter (latest stable): Customer, Vendor, Rider | Play Store / App Store |
| Push notifications | Firebase Cloud Messaging only (no Firestore, no Dynamic Links) | Firebase |
| Payments | Paystack and Flutterwave (card, bank transfer, USSD), wallet, pay-on-delivery | — |

Laravel is the single source of truth for users, auth, orders, payments and wallets. Supabase never accepts writes from apps for money-related tables.

## 3. Repository layout (monorepo)

```
wegodeliva-platform/
├── CLAUDE.md
├── docs/
│   ├── spec/            # Phase 0 functional spec (own words)
│   ├── adr/             # architecture decision records
│   └── runbooks/        # deploy, rollback, incident, data migration
├── backend/             # Laravel 12 API
├── web/                 # Next.js admin + vendor portal (Vercel)
├── mobile/              # Flutter, managed with Melos
│   ├── packages/core    # API client, models, auth, errors, logging
│   ├── packages/ui      # design system: theme, tokens, components
│   ├── apps/customer
│   ├── apps/vendor
│   └── apps/rider
├── supabase/            # migrations, RLS policies, storage policies
├── scripts/
└── .github/workflows/   # CI for all packages
```

## 4. Standards

### Security (every phase)
- Secrets only in environment variables or the hosting provider's secret store. Never commit `.env`, keystores, `google-services.json`, service-account keys or API keys. Provide `.env.example` only.
- `APP_DEBUG=false` in staging and production. Generic error responses to clients; details go to logs.
- Payments: verify every transaction server-side against the gateway, checking **reference, amount and currency**. Verify webhook signatures (Paystack `x-paystack-signature`, Flutterwave `verif-hash`). Make payment and wallet operations idempotent and wrap them in DB transactions with row locks.
- Rate-limit login, OTP, password reset, checkout and wallet endpoints.
- Authorisation via policies on every resource; customers, vendors and riders can only touch their own data.
- Supabase: Row Level Security on every table; apps use the anon key + Laravel-minted JWT; the service-role key exists only on the server.
- Mobile: tokens in secure storage (Keychain/Keystore); no cleartext HTTP; no `print` of user data; restrict Google API keys by package/bundle ID and SHA-1.
- Dependencies: Dependabot or Renovate on; `composer audit`, `npm audit` and `flutter pub outdated` in CI.

### Code quality
- Backend: PSR-12, Laravel Pint, PHPStan (level 6+), Pest tests. Feature tests for every endpoint; 100% coverage of pricing, payment, wallet and order-state logic.
- Web: TypeScript strict, ESLint, Prettier, Vitest + Playwright for critical admin flows.
- Mobile: Riverpod for state, go_router for navigation, Dio for HTTP, freezed/json_serializable models, gen-l10n for strings, `flutter analyze` with zero warnings, unit + widget tests for cart, checkout and tracking.
- API versioned under `/api/v1`, documented with OpenAPI (generated), consistent error format.
- Conventional Commits; small PRs; every PR describes what changed and how it was tested.

### Platform requirements
- Android: target the API level Google Play currently requires (API 36 as of late 2026), edge-to-edge, predictive back, foreground service type `location` for the rider app, runtime Bluetooth permissions for receipt printing.
- iOS: current Xcode, privacy manifest, Sign in with Apple wherever other social logins are offered.
- Deep links: Android App Links + iOS Universal Links on `wegodeliva.com` (Firebase Dynamic Links is shut down).

### Design
- Material 3, one shared theme in `mobile/packages/ui` and matching tokens in `web/`.
- Brand: navy `#0B1220` (base), orange `#FF6B1A` (accent/primary actions). Navy text on orange buttons (white on orange fails contrast). Dark mode supported.
- Accessibility: semantic labels on all icons and images, 48dp tap targets, dynamic text size.
- App names: "WeGoDeliva", "WeGoDeliva Vendor", "WeGoDeliva Rider".

## 5. Phases and acceptance criteria

**Phase 0 — Specification (read legacy, write spec).**
Produce `docs/spec/`: entities and fields, order lifecycle and states, pricing/fees/commission rules, payment and wallet flows, roles and permissions, notifications, every screen per app, admin functions. Mark modules WeGoDeliva doesn't use (taxi, service booking, Paytm, Razorpay, iPay88, etc.) as "out of scope" and confirm with the owner. Also write `docs/spec/data-migration.md` mapping old MySQL tables to the new schema. *Done when the owner approves the spec.*

**Phase 1 — Foundations.**
Monorepo, CI on every PR, clean-room check, Laravel 12 skeleton on Supabase Postgres, auth (phone OTP + email, Sanctum), roles, health checks, staging environment, Next.js admin shell on Vercel with login, Flutter core + ui packages, flavours (dev/staging/prod). *Done when CI is green and staging is reachable.*

**Phase 2 — Core marketplace backend.**
Vendors, categories, products, stock, zones/delivery fees, cart, checkout, orders and state machine, payments (Paystack, Flutterwave, wallet, pay-on-delivery), refunds, payouts, ratings, notifications. *Done when feature tests cover every endpoint and payment tests include tampered-amount and replayed-transaction cases.*

**Phase 3 — Customer app.**
Home, search, store, cart, checkout (3 steps), live tracking, order history and reorder, wallet, addresses with landmarks and pin-drop, campus mode, referral via App Links, support (WhatsApp + in-app issue report).

**Phase 4 — Vendor app + vendor web portal.**
Order inbox with sound and accept/reject/prep time, open/closed/busy, menu and stock, earnings and statements, receipt printing.

**Phase 5 — Rider app + realtime.**
Online/offline, job offers, step-by-step delivery with handover code, background location via Supabase Realtime, earnings and cash-on-delivery balance, low-data mode.

**Phase 6 — Admin panel.**
Dashboards, users/vendors/riders, approvals and documents, orders, refunds, payouts, zones and fees, promos, content, settings, audit log.

**Phase 7 — Data migration and launch.**
Rehearse the MySQL → Postgres migration on staging with a copy of production data; reconcile user counts, order counts and every wallet balance to the kobo. Release apps under the **existing** Play Store/App Store package IDs and signing keys so current users get an update, not a new app. Cut over with a written rollback plan. *Done when reconciliation passes and the owner signs off.*

## 6. Working rules for Claude

- Start each session by reading this file and `docs/progress.md`; end each session by updating `docs/progress.md` (done, in progress, next, blockers).
- Ask the owner before: deleting data, touching production (servers, cPanel, DNS, Play Console, live database), spending money (creating paid Supabase/Vercel resources), or changing scope.
- Never paste, log or commit secrets. If a secret is found in code, stop and report it.
- Record significant decisions as ADRs in `docs/adr/`.
- Prefer boring, well-supported technology over clever solutions.
- When unsure about a business rule, ask rather than guess.
