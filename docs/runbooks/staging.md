# Staging: Laravel Cloud + Supabase + Vercel

Owner steps to bring up staging (Phase 1 "done" = staging reachable). Nothing here costs money on
the free plans named; stop and ask before choosing any paid option.

## 1. Supabase (done 2026-11-16)
Project `WeGoDeliva` (ref `ydoczmolxngbrlkervbs`, eu-central-1, free plan). Prepared by
`supabase/migrations/20261116142353_lock_down_public_schema_for_laravel.sql`:
public tables are not exposed to the Data API, RLS is switched on for every new table, and
PostGIS is installed in the `extensions` schema. Laravel creates its own tables on first deploy.

Connection values for Laravel, from Supabase → project → **Connect** → **Session pooler**
(the direct `db.<ref>.supabase.co` host may be IPv6-only and unreachable):

| Laravel variable | Value |
| --- | --- |
| `DB_HOST` | the session pooler host shown (`aws-…-eu-central-1.pooler.supabase.com`) |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | `postgres` |
| `DB_USERNAME` | `postgres.ydoczmolxngbrlkervbs` |
| `DB_PASSWORD` | the database password (Project Settings → Database → reset it if unknown) |
| `DB_SSLMODE` | `require` |
| `DB_SEARCH_PATH` | `public,extensions` (the default; set it explicitly anyway) |

## 2. Laravel Cloud (API)
1. New application → GitHub `Alpha-Bytes-Tech/wegodeliva-platform`, branch `main`. Cloud detects
   the monorepo: choose **`backend`** as the root directory. PHP 8.3.
2. Do **not** add a Laravel Cloud database or cache (paid); Supabase is the database.
3. Environment variables (Laravel Cloud generates `APP_KEY`):
   ```
   APP_ENV=staging
   APP_DEBUG=false
   APP_URL=<the app URL Laravel Cloud shows>
   DB_CONNECTION=pgsql
   (DB_* from section 1)
   CACHE_STORE=database
   QUEUE_CONNECTION=database
   SESSION_DRIVER=array
   LOG_LEVEL=info
   SUPER_ADMIN_NAME=…
   SUPER_ADMIN_EMAIL=…
   SUPER_ADMIN_PHONE=+234…
   SUPER_ADMIN_PASSWORD=… (12+ characters)
   (MAIL_* from section 4; until then MAIL_MAILER=log)
   ```
4. Deploy commands: `php artisan migrate --force` then `php artisan db:seed --force`
   (seeders are idempotent).
5. Add one **queue worker** (`php artisan queue:work`): OTP emails are queued.
6. Check `https://<app URL>/api/v1/health/ready` returns 200.

## 3. Vercel (admin panel)
Project `wegodeliva-platform`: root directory `web`, framework Next.js, Node 22 (set 2026-11-16).
Once the API is up, set `API_BASE_URL=https://<laravel app URL>/api/v1` for Production and
Preview, and redeploy. The project must not hold Supabase or database secrets.

## 4. Transactional email (OTP codes)
Free options (check the provider's pricing page before signing up; limits change):
- **Brevo** — free plan, about 300 emails/day, SMTP included; free emails carry a Brevo logo.
- **Resend** — free plan, about 3,000/month capped at 100/day, one domain; SMTP or API.

Use a sending subdomain (`mail.wegodeliva.com`) so the root domain's existing DNS is untouched.

Brevo setup:
1. Sign up at brevo.com (company: Alpha Bytes Technologies Ltd).
2. Senders, Domains & Dedicated IPs → **Domains** → add `mail.wegodeliva.com`; add the DNS records
   it shows (DKIM, Brevo code, DMARC) at the wegodeliva.com DNS host; wait for "Authenticated".
3. Senders → add `no-reply@mail.wegodeliva.com`.
4. SMTP & API → **SMTP** → generate an SMTP key.
5. In Laravel Cloud:
   ```
   MAIL_MAILER=smtp
   MAIL_HOST=smtp-relay.brevo.com
   MAIL_PORT=587
   MAIL_SCHEME=smtp
   MAIL_USERNAME=<the SMTP login Brevo shows>
   MAIL_PASSWORD=<the SMTP key>
   MAIL_FROM_ADDRESS=no-reply@mail.wegodeliva.com
   MAIL_FROM_NAME=WeGoDeliva
   ```
Resend uses the same shape: host `smtp.resend.com`, port 587, username `resend`, password = API key.
