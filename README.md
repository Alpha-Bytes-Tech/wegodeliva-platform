# WeGoDeliva Platform

Monorepo for WeGoDeliva, a multivendor delivery and services marketplace in Nigeria (Alpha Bytes Technologies Ltd).

| Folder | What | Stack |
| --- | --- | --- |
| `backend/` | Core API and business logic | Laravel 12, PHP 8.3+, Postgres (Supabase) |
| `web/` | Admin panel and vendor portal | Next.js (App Router, TypeScript), Vercel |
| `mobile/` | Customer, Vendor and Rider apps + shared packages | Flutter, Melos |
| `supabase/` | Migrations for RLS, storage and realtime policies | Supabase CLI |
| `docs/spec/` | Functional specification (source of truth for behaviour) | |
| `docs/adr/` | Architecture decision records | |
| `docs/runbooks/` | Operations procedures | |
| `scripts/` | Repo tooling (clean-room check, …) | |

Start with [CLAUDE.md](CLAUDE.md) (project rules) and [docs/progress.md](docs/progress.md) (current status).

## First-time setup

```bash
git config core.hooksPath .githooks   # runs the clean-room check before every commit
```

Per-app setup lives in each folder's README.

## Rules in one line each

- Clean rewrite: no code from the old system (`scripts/check-clean-room.sh` enforces names).
- No secrets in git: use `.env.example` and the hosting providers' secret stores.
- Conventional Commits, small PRs, CI must be green.
