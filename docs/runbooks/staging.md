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

## 2. Laravel Cloud (API) (done 2026-11-18)
Live at `https://wegodeliva-platform-production-p9jmsm.laravel.cloud` (environment `production`,
used as staging, region US East). Health: `/api/v1/health/ready`.

1. New application → GitHub `Alpha-Bytes-Tech/wegodeliva-platform`, branch `main`. Cloud detects
   the monorepo: choose **`backend`** as the root directory. PHP 8.3.
2. Do **not** add a Laravel Cloud database or cache (paid); Supabase is the database.
3. Environment variables. Laravel Cloud did **not** inject `APP_KEY` for us: generate one with
   `php artisan key:generate --show` in the **Commands** tab and add it as `APP_KEY=base64:…`.
   Values are read like a `.env` file, so use letters and digits only for passwords: `#` starts a
   comment and `$` is expanded (this broke the first `DB_PASSWORD` and `SUPER_ADMIN_PASSWORD`).
   ```
   APP_KEY=base64:…
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
   SUPER_ADMIN_PASSWORD=… (12+ characters; delete the line after the first deploy)
   (MAIL_* from section 4)
   ```
   `MAIL_MAILER=log` is of little use here: the log mailer writes at debug level (hidden by
   `LOG_LEVEL=info`), and output from the Commands tab and queue workers did not appear in the
   Monitoring → Logs viewer. Set up the email provider (section 4) before relying on codes.
4. Deploy commands: `php artisan migrate --force` then `php artisan db:seed --force`
   (seeders are idempotent).
5. **Queue worker (required):** Environment → App cluster → background processes → New: connection
   `database`, queue `default`, sleep `3`, backoff `10`, tries `3`, max time `3600`, 1 process. Do not
   add a worker cluster or managed queue (extra compute). Without a worker, OTP emails stay queued.
6. Check `https://<app URL>/api/v1/health/ready` returns 200.

### Locked-out admin
Set `SUPER_ADMIN_PASSWORD` to the new password (letters and digits), save, redeploy, then in the
Commands tab run `php artisan admin:reset-password <email> --from-env`. It signs the account out
everywhere and writes the audit log. Delete `SUPER_ADMIN_PASSWORD` again afterwards. On a machine
with a terminal, run it without `--from-env` and it asks for the password instead.

### Later
- The app runs in US East and the database in eu-central-1: every query crosses the Atlantic.
  Move the Laravel Cloud environment to an EU region before launch.
- Scale to zero is on: fine for staging; turn it off for launch.

## 3. Vercel (admin panel)
Project `wegodeliva-platform`: root directory `web`, framework Next.js, Node 22 (set 2026-11-16).
`API_BASE_URL=https://wegodeliva-platform-production-p9jmsm.laravel.cloud/api/v1` (Production and
Preview, set 2026-11-18). Admin panel: `https://wegodeliva-platform.vercel.app` (behind Vercel
Authentication). The project must not hold Supabase or database secrets.

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
