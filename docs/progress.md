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
- 2026-11-01 — Second round of answers (R1–R8) applied. App IDs are kept (Play App Signing is on; upload key reset needed). Vendors charge and remit their own VAT; WeGoDeliva charges VAT on its commission; default VAT table per category researched. Wallet licensing researched: recommend launching with "WeGoDeliva Credit" (no cash top-ups) pending owner approval. Riders are test accounts too.

## In progress

- Nothing. Waiting for the owner's go-ahead to start Phase 1, and the S2 wallet decision.

## Next (Phase 1 — Foundations)

1. Monorepo layout, `.gitattributes` (LF), `.editorconfig`, `.gitignore` (secrets, keystores, Firebase files).
2. `scripts/check-clean-room.sh` (excludes `docs/spec/legacy-notes.md` and `CLAUDE.md`) + pre-commit hook + CI job.
3. CI on every PR (backend, web, mobile).
4. Laravel 12 skeleton on Supabase Postgres (staging project), Sanctum, roles, phone OTP + email auth (both required), Google/Apple sign-in, health checks. OTP goes to the log in dev/staging until the Termii account exists.
5. Next.js admin shell on Vercel with login + TOTP.
6. Flutter Melos workspace: `core` + `ui` packages, three app shells with dev/staging/prod flavours (`com.wegodeliva.{customerapp,vendorapp,riderapp}` + `.dev`/`.staging`).
7. ADRs: money in kobo, order/booking state machines, realtime + chat via Supabase, dispatch, commission-at-withdrawal ledger with VAT on commission, wallet/credit model.

## Blockers / owner actions

See [spec/open-questions.md](spec/open-questions.md) "Remaining questions":
- ★ **S2 Wallet decision**: launch with WeGoDeliva Credit (no cash top-ups)? Doesn't block starting Phase 1.
- **S1** Export the services categories from the live database (cPanel → phpMyAdmin).
- **S3** Lawyer (wallet and earnings holding) and accountant (VAT table) sign-off before launch.
- **S4** Create the Termii account and register a sender ID (owner, since only the owner can create accounts).
- **S5** Support WhatsApp numbers.
- Before the first store release (Phase 7): request an upload key reset in Play Console for each app, and give the team access to the Apple Developer account.
- **Secrets in legacy code** (not copied into this repo): restrict or rotate the Google Maps keys in the customer and rider apps.
- Access needed for Phase 1 staging: Supabase org, Vercel team, Firebase project (add the team as editor), GitHub admin (CI secrets, branch protection). Creating paid resources needs owner approval.
- Leftover original copy of the customer app at `../Wegodeliva Apps/WegoDeliva CustomerApp`: owner can delete it.
