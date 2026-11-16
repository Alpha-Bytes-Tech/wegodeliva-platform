# Supabase

SQL applied to the Supabase project outside Laravel's own migrations: privileges, RLS, storage
and Realtime policies (CLAUDE.md §2–§4). Laravel's tables come from `backend/database/migrations`.

| Environment | Project | Region |
| --- | --- | --- |
| Staging | `WeGoDeliva` (ref `ydoczmolxngbrlkervbs`), org "We Go Deliva", free plan | eu-central-1 |

## Rules
- Tables in `public` are created by Laravel and are **not** reachable through the Data API:
  `anon`/`authenticated` get no grants by default and every new table gets RLS automatically
  (`migrations/20261116142353_lock_down_public_schema_for_laravel.sql`).
- Anything the apps must read from Supabase directly (Realtime, Storage) is granted explicitly,
  per table, together with RLS policies, in a new file here.
- Each file is named `<version>_<name>.sql` with the version Supabase recorded when it was applied.
