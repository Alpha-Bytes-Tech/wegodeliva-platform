# Progress

Read this at the start of every session; update it at the end.

## Current phase: 1 — Foundations · **in review** (PR "Phase 1: foundations", branch `phase-1/foundations`)

Last updated: 2026-11-01

## Done

- 2026-11-01 — Repository initialised (`main`), connected to `github.com/Alpha-Bytes-Tech/wegodeliva-platform` (private), first commit with `CLAUDE.md` pushed.
- 2026-11-01 — Legacy code read once (backend, customer, vendor and rider apps in `../legacy/`) for Phase 0 only. From Phase 1 on, build from `docs/spec/` only.
- 2026-11-01 — Functional specification written in `docs/spec/`.
- 2026-11-01 — Owner confirmed the out-of-scope list, then amended it: **services** and **in-app chat** are in scope at launch.
- 2026-11-01 — Owner approved the rest of the spec and answered open questions 3–27. Applied to the spec: nine launch campuses across five cities (multi-city), all six verticals, 5% vendor / 3% delivery commission settled at withdrawal, VAT rules, vendor-funded coupons, per-additional-stop parcel fee, refund fault policy, CBN-tier closed-loop wallet, pay on delivery up to ₦20,000, payouts via gateway transfers, 5%/₦5,000 referral, handover code on every delivery, three rider types, email + phone required, Google + Apple sign-in, existing Firebase project kept, and old data treated as test data.
- 2026-11-01 — CLAUDE.md updated: clean-room check excludes CLAUDE.md (owner approved); product description, Phase 0/3/7 wording aligned with the owner's decisions.
- 2026-11-01 — Service categories (14) added to the spec as seed data. OTP switched from SMS to **email** (owner decision): no SMS provider at launch, phones collected but not verified yet, parcel stop codes shared from the sender's app.
- 2026-11-01 — Second round of answers (R1–R8) applied. App IDs are kept (Play App Signing is on; upload key reset needed). Vendors charge and remit their own VAT; WeGoDeliva charges VAT on its commission; default VAT table per category researched. Wallet licensing researched; owner decided to keep cash top-ups with funds held at Paystack/Flutterwave (app shows balances only) and confirmed both gateways can hold earnings until withdrawal. Daily ledger-vs-gateway reconciliation added. Riders are test accounts too.

- 2026-11-01 — Phase 1 built in a cloud session (branch `phase-1/foundations`, one PR into `main`, not merged):
  - **CI fixed**: the workflow on `main` was invalid (`hashFiles()` in job-level `if`), so no job had ever run. Fixed, plus `pull-requests: read` for paths-filter.
  - **backend/**: Laravel 12 API on Postgres (PostGIS-ready). Users/roles/OTP/devices/auth identities/append-only audit log/cities/zones/vendor types/categories. Auth: customer sign-up (role always customer), email OTP send/verify, password login with app role hint, admin login, password reset by OTP, Google/Apple ID-token verification (JWKS) behind an interface, logout, me; app-scoped Sanctum tokens; spec 14 error envelope; rate limits per spec 13; device registration; health/readiness; audit-log endpoint. Seeders: vendor types, 14 service categories, 6 cities + 9 campus zones, super admin from env. 122 Pest tests, 96% coverage, Pint, Larastan level 6, composer audit clean. Docker Compose with PostGIS + Mailpit.
  - **web/**: Next.js 16 admin shell: login through a route handler that keeps the token in an httpOnly cookie, protected layout with spec 12 sections, dashboard placeholder, brand tokens + dark mode. ESLint/Prettier, Vitest (12 tests), Playwright smoke test, build.
  - **mobile/**: Melos 8 pub workspace: `core`, `ui`, `auth` packages; customer/vendor/rider shells (splash → email → code → home), dev/staging/prod flavours, Android IDs kept, target SDK 36. 44 unit/widget tests, analyze clean.
  - ADRs 0009 (roles table), 0010 (app-scoped tokens, cookie session for admin), 0011 (pub workspace + shared auth package).

## In progress

- Owner review of the Phase 1 PR.

## Next

1. Merge Phase 1 after review; turn on branch protection requiring the CI jobs.
2. Staging (needs the owner, see below): Supabase project, Laravel host, Vercel project for `web/`, email provider, then deploy and point the staging flavour at it.
3. Phase 1 leftovers: admin TOTP; iOS flavour schemes + privacy manifest + Sign in with Apple capability (needs a Mac); FCM wiring once Firebase access is granted; OpenAPI generation for the API; `X-App-Version` / 426 check.
4. Phase 2 — core marketplace backend (vendors, catalogue, zones/fees, cart, checkout, orders, payments).

## Blockers / owner actions

**Needed for staging (Phase 1 "done" criterion). Nothing was created or paid for in this session.**
- **Supabase**: create (or give the team access to) the organisation and a staging project; enable PostGIS. Share the database connection strings via the host's secret store, not chat.
- **Laravel hosting**: choose Laravel Cloud or a VPS. The organisation already has the Laravel Cloud and Vercel GitHub apps installed, so Laravel Cloud is the shortest path.
- **Vercel**: team access for the `web/` project (root directory `web`, env `API_BASE_URL`).
- **Transactional email provider (S4)**: account + API key; SPF/DKIM/DMARC on wegodeliva.com. Until then OTP emails only work locally (Mailpit).
- **Firebase**: add the team as editor on the existing project (FCM only).
- **Google / Apple sign-in**: OAuth client IDs (Google Cloud console; Apple Services ID) — set `GOOGLE_CLIENT_IDS` / `APPLE_CLIENT_IDS`.
- **GitHub admin**: branch protection on `main` requiring the four CI jobs.

**Questions for the owner**
- A user with another role (e.g. a rider) who also wants to order food: should the customer app give them the customer role automatically after email verification, or keep the spec rule that each app accepts only users who already hold its role? Phase 1 follows the spec (rejects).
- Admin "permissions per role" (spec 12.2): fixed in code (ADR 0009) unless you want admins to edit them at runtime.

**Earlier items still open**
See [spec/open-questions.md](spec/open-questions.md) "Remaining questions":
- **S3** before launch: written confirmation from Paystack and Flutterwave that their terms allow stored customer balances and held earnings; fintech-lawyer opinion; accountant sign-off on the VAT table.
- **S4** Choose a transactional email provider for OTP and receipts (several have free tiers) and set up SPF/DKIM/DMARC on wegodeliva.com. The owner creates the account; the team adds the API key to secrets.
- **S5** Support WhatsApp numbers.
- Before the first store release (Phase 7): request an upload key reset in Play Console for each app, and give the team access to the Apple Developer account.
- **Secrets in legacy code** (not copied into this repo): restrict or rotate the Google Maps keys in the customer and rider apps.
- Leftover original copy of the customer app at `../Wegodeliva Apps/WegoDeliva CustomerApp`: owner can delete it.
