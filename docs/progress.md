# Progress

Read this at the start of every session; update it at the end.

## Current phase: 0 — Specification · **waiting for owner review**

Last updated: 2026-11-01

## Done

- 2026-11-01 — Repository initialised (`main`), connected to `github.com/Alpha-Bytes-Tech/wegodeliva-platform` (private), first commit with `CLAUDE.md` pushed.
- 2026-11-01 — Legacy code read once (backend, customer, vendor and rider apps in `../legacy/`) for Phase 0 only.
- 2026-11-01 — Functional specification drafted in `docs/spec/` (scope, roles, entities, order lifecycle, pricing/commission, payments/wallet/payouts, dispatch, notifications, every screen of the 3 apps, admin panel, settings, API behaviour, data-migration plan, legacy defects, open questions).

- 2026-11-01 — Owner confirmed the out-of-scope list as proposed (O1–O11 out; Q1–Q12 per team suggestion). Spec updated.

## In progress

- Owner review of the rest of the spec.
- ★ questions in [spec/open-questions.md](spec/open-questions.md) (questions 1–2 answered).

## Next (Phase 1 — Foundations, after approval)

1. Monorepo layout, `.gitattributes`, `.editorconfig`, `.gitignore` (secrets, keystores, Firebase files).
2. `scripts/check-clean-room.sh` + pre-commit hook + CI job. Note: `CLAUDE.md` itself names the old template, so the check must also exclude `CLAUDE.md` (owner to approve this one-line rule change).
3. CI on every PR (backend, web, mobile).
4. Laravel 12 skeleton on Supabase Postgres (staging project), Sanctum, roles, phone OTP + email auth, health checks.
5. Next.js admin shell on Vercel with login.
6. Flutter Melos workspace: `core` + `ui` packages, three app shells with dev/staging/prod flavours, keeping the live package IDs.
7. ADRs for the main decisions (money in kobo, state machine, realtime via Supabase, dispatch design).

## Blockers / owner actions

- ★ Approve the spec and answer the ★ open questions.
- ★ **Secrets in legacy code** (found 2026-11-01, not copied into this repo):
  - Google Maps API keys hard-coded in the customer and rider apps → restrict them in Google Cloud Console (package name + SHA-1, bundle ID) or rotate them.
  - Firebase config files in all three apps → decide whether to keep the existing Firebase project (question 24).
  - `upload-keystore.jks` in all three apps → move to a secure vault with their passwords; confirm Play App Signing status. **Required to ship updates in Phase 7.**
- Access needed before Phase 1 staging: Supabase org, Vercel team, Firebase project, GitHub admin (for CI secrets and branch protection). Creating paid resources needs owner approval.
- Leftover original copy of the customer app at `../Wegodeliva Apps/WegoDeliva CustomerApp` (a copy now lives in `../legacy/customer-app`) — owner can delete it once nothing has it open.
