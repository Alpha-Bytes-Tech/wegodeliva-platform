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

## In progress

- Nothing. Waiting for the owner's go-ahead to start Phase 1.

## Next (Phase 1 — Foundations)

1. Monorepo layout, `.gitattributes` (LF), `.editorconfig`, `.gitignore` (secrets, keystores, Firebase files).
2. `scripts/check-clean-room.sh` (excludes `docs/spec/legacy-notes.md` and `CLAUDE.md`) + pre-commit hook + CI job.
3. CI on every PR (backend, web, mobile).
4. Laravel 12 skeleton on Supabase Postgres (staging project), Sanctum, roles, phone OTP + email auth (both required), Google/Apple sign-in, health checks. OTP sent to the log in dev/staging until an SMS provider exists.
5. Next.js admin shell on Vercel with login + TOTP.
6. Flutter Melos workspace: `core` + `ui` packages, three app shells with dev/staging/prod flavours (package IDs per R1).
7. ADRs: money in kobo, order/booking state machines, realtime + chat via Supabase, dispatch, commission-at-withdrawal ledger, closed-loop wallet.

## Blockers / owner actions

See [spec/open-questions.md](spec/open-questions.md) "Remaining questions":
- ★ **R1 App signing**: check whether Play App Signing is on for the three apps, and who owns the Apple Developer account. This decides whether we keep the old package IDs.
- ★ **R2 SMS**: approve opening a Termii account (paid) for OTP.
- R3 accountant (VAT), R4 legal (wallet licensing, CBN tier figures), R5 old riders, R6 service categories, R7 cancellation fee, R8 campus boundaries, R9 WhatsApp numbers.
- **Secrets in legacy code** (found 2026-11-01, not copied into this repo): restrict or rotate the Google Maps keys in the customer and rider apps. The Firebase project is kept, so its config files will be re-downloaded per flavour rather than copied. The keystores are unusable without passwords — see R1.
- Access needed for Phase 1 staging: Supabase org, Vercel team, Firebase project (add the team as editor), GitHub admin (CI secrets, branch protection). Creating paid resources needs owner approval.
- Leftover original copy of the customer app at `../Wegodeliva Apps/WegoDeliva CustomerApp` (a copy lives in `../legacy/customer-app`): owner can delete it.
