# WeGoDeliva API (Laravel 12)

The core API and business logic: users, auth, roles and (from Phase 2) orders, payments and
wallets. Behaviour comes from [`docs/spec/`](../docs/spec/); decisions from [`docs/adr/`](../docs/adr/).

## Requirements

- PHP 8.3+ with `pdo_pgsql`, `intl`, `mbstring`, `bcmath` (and `pcov` for coverage)
- Composer 2
- Docker (for Postgres + PostGIS and Mailpit), or your own Postgres 16 with PostGIS

## Local setup

```bash
cd backend
docker compose up -d                 # Postgres (PostGIS) on 5432, Mailpit on 1025/8025
cp .env.example .env
php artisan key:generate
# Set SUPER_ADMIN_EMAIL, SUPER_ADMIN_PHONE and SUPER_ADMIN_PASSWORD (12+ chars) in .env
composer install
php artisan migrate --seed
php artisan serve                    # http://localhost:8000/api/v1/health
php artisan queue:work               # sends OTP emails (they are queued)
```

OTP emails land in Mailpit: <http://localhost:8025>.

## Quality checks (same as CI)

```bash
vendor/bin/pint --test                                  # code style (Laravel preset, PSR-12 based)
vendor/bin/phpstan analyse --memory-limit=1G            # Larastan, level 6
vendor/bin/pest --coverage --min=80                     # tests on Postgres (wegodeliva_test)
composer audit
```

Tests use the `wegodeliva_test` database (created by `docker/postgres-init.sql`). Connection
settings are in `phpunit.xml` and can be overridden with `DB_*` environment variables.

## API overview (`/api/v1`)

Every error uses the spec 14 §14.1 envelope:
`{ "error": { "code": "otp_expired", "message": "…", "fields": {} } }`
(400 validation, 401, 403, 404 also for other people's data, 409, 422 business rule, 429, 500 generic).

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/health` | Liveness |
| GET | `/health/ready` | Database, cache and queue; 503 if any fails |
| POST | `/auth/register` | Customer sign-up: `name, email, phone, password?, referral_code?, accept_terms`. Role is always `customer`. Sends an email OTP. |
| POST | `/auth/otp/send` | `email, app, purpose (login\|reset)`. Same 202 answer whether or not the account exists. |
| POST | `/auth/otp/verify` | `email, code, app, device_name?` → token. Verifies the email. |
| POST | `/auth/login` | `email, password, app (customer\|vendor\|rider)` → token. Email must be verified. |
| POST | `/auth/password/reset` | `email, code, password, password_confirmation`. Revokes all tokens. |
| POST | `/auth/social/{google\|apple}` | `id_token, phone?, name?` (customer app). First sign-in needs `phone` (`phone_required`). |
| GET | `/auth/me` | Current user |
| POST | `/auth/logout` | Revokes the current token |
| POST | `/devices` | Register an FCM token for the token's app |
| DELETE | `/devices/{uuid}` | Own devices only (404 otherwise) |
| POST | `/admin/auth/login` | Admin panel: `email, password` → `app:admin` token. **TOTP is a TODO.** |
| GET | `/admin/audit-log` | Admin / super admin with an admin-app token. Cursor pagination. |

### Tokens and apps

Each Sanctum token carries one ability: `app:customer`, `app:vendor`, `app:rider` or `app:admin`.
An app only signs in users holding one of its roles (customer app → `customer`; vendor app →
`vendor_owner`/`vendor_staff`; rider app → `rider`; admin panel → `admin`/`super_admin`/`zone_admin`).
Riders and vendor owners/staff who sign in to the customer app are given the `customer` role
automatically (owner decision); admin-only accounts are not.
Admin endpoints require the `app:admin` ability **and** an admin role, so an admin's customer-app
token cannot reach them. Token lifetimes: 60 days for apps, 12 hours for admin (env-configurable).

### One-time codes

6 digits, stored hashed (bcrypt), valid 10 minutes, 5 attempts, a new code replaces the old one,
one send per 60 s per address and purpose, 5 per email and 20 per IP per hour (spec 13). The email
names the requesting app and contains no link (ADR 0008).

### Social sign-in

`App\Services\Social\SocialTokenVerifier` verifies Google/Apple ID tokens (signature via the
provider's JWKS, expiry, issuer, audience). Set `GOOGLE_CLIENT_IDS` and `APPLE_CLIENT_IDS`
(comma-separated, public identifiers) once the OAuth clients exist; until then every social
sign-in is rejected. Tests use a fake verifier and locally generated keys.

## Data notes

- Money columns are integer kobo (`*_kobo`, ADR 0002).
- `zones.polygon` is `geography(MultiPolygon, 4326)` when PostGIS can be enabled; the migration
  skips the column otherwise. On Supabase, enable PostGIS (Database → Extensions) before migrating.
- `admin_audit_log` is append-only: a Postgres trigger rejects UPDATE and DELETE.
- Roles live in a plain `roles` / `role_user` table seeded by its migration (ADR 0009).

## Seed data

`php artisan db:seed` (idempotent): six vendor types, the 14 service categories, six launch
cities with nine campus zones (inactive until ops draw their boundaries), and the super admin from
`SUPER_ADMIN_*` env vars (skipped if unset; no default password).

## Supabase connection

Use the **direct** connection (port 5432) for `php artisan migrate`, and the **pooled**
connection (Supavisor, port 6543, transaction mode) for the running app. Set `DB_SSLMODE=require`.
The service-role key never goes into this app's config for clients; only Laravel talks to the
database.
