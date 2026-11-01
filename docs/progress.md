# Progress

Read this at the start of every session; update it at the end.

## Current phase: 0 — Specification · **approved by owner 2026-11-01** · Phase 1 not started (waiting for go-ahead)

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

## In progress

- Nothing. Waiting for the owner's go-ahead to start Phase 1.

## Next (Phase 1 — Foundations)

1. Monorepo layout, `.gitattributes` (LF), `.editorconfig`, `.gitignore` (secrets, keystores, Firebase files).
2. `scripts/check-clean-room.sh` (excludes `docs/spec/legacy-notes.md` and `CLAUDE.md`) + pre-commit hook + CI job.
3. CI on every PR (backend, web, mobile).
4. Laravel 12 skeleton on Supabase Postgres (staging project), Sanctum, roles, email OTP + password auth (email and phone both required; phone not verified at launch), Google/Apple sign-in, health checks. OTP emails go to a mail catcher (Mailpit) in dev and to the real provider in staging.
5. Next.js admin shell on Vercel with login + TOTP.
6. Flutter Melos workspace: `core` + `ui` packages, three app shells with dev/staging/prod flavours (`com.wegodeliva.{customerapp,vendorapp,riderapp}` + `.dev`/`.staging`).
7. ADRs: money in kobo, order/booking state machines, realtime + chat via Supabase, dispatch, commission-at-withdrawal ledger with VAT on commission, wallet/credit model.

## Blockers / owner actions

See [spec/open-questions.md](spec/open-questions.md) "Remaining questions":
- **S3** before launch: written confirmation from Paystack and Flutterwave that their terms allow stored customer balances and held earnings; fintech-lawyer opinion; accountant sign-off on the VAT table.
- **S4** Choose a transactional email provider for OTP and receipts (several have free tiers) and set up SPF/DKIM/DMARC on wegodeliva.com. The owner creates the account; the team adds the API key to secrets.
- **S5** Support WhatsApp numbers.
- Before the first store release (Phase 7): request an upload key reset in Play Console for each app, and give the team access to the Apple Developer account.
- **Secrets in legacy code** (not copied into this repo): restrict or rotate the Google Maps keys in the customer and rider apps.
- Access needed for Phase 1 staging: Supabase org, Vercel team, Firebase project (add the team as editor), GitHub admin (CI secrets, branch protection). Creating paid resources needs owner approval.
- Leftover original copy of the customer app at `../Wegodeliva Apps/WegoDeliva CustomerApp`: owner can delete it.
