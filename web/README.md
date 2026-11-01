# WeGoDeliva web (admin panel + vendor portal)

Next.js (App Router, TypeScript strict) deployed on Vercel. Phase 1 contains the **admin shell**:
login, a protected layout with the spec 12 sections as placeholders, and a dashboard placeholder.

## Setup

```bash
cd web
cp .env.example .env.local   # point API_BASE_URL at the Laravel API
npm ci
npm run dev                  # http://localhost:3000
```

The backend must be running (see `../backend/README.md`) and you need an admin account. Locally,
the seeded super admin comes from `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD` in the backend `.env`.

## How sign-in works

1. The login form posts email + password to our own route handler `POST /api/session`.
2. The handler calls the backend's `POST /api/v1/admin/auth/login`, receives a Sanctum token
   scoped to the `admin` ability, and stores it in an **httpOnly, SameSite=Strict** cookie
   (`Secure` in production). The token is never returned to browser JavaScript.
3. `src/proxy.ts` redirects requests without the cookie to `/login`; the `(admin)` layout validates
   the token on every request through `GET /api/v1/auth/me` and checks for an admin role.
4. Sign out (`DELETE /api/session`) revokes the token on the backend and clears the cookie.

**TODO (before launch):** TOTP second factor for admins (spec 02 §2.4). Marked in
`src/app/api/session/route.ts`.

## Scripts

| Script | What |
| --- | --- |
| `npm run lint` | ESLint (Next.js rules) + Prettier check |
| `npm run typecheck` | Generates route types, then `tsc --noEmit` |
| `npm test` | Vitest unit/component tests (`tests/unit`) |
| `npm run test:e2e` | Playwright smoke test (`e2e/`, runs against `npm run start`; run `npm run build` first) |
| `npm run build` | Production build |

## Design tokens

`src/app/globals.css` holds the brand tokens (navy `#0B1220`, orange `#FF6B1A`, navy text on
orange buttons) with a dark-mode variant via `prefers-color-scheme`. They mirror
`mobile/packages/ui`.

## Environment

| Variable | Where | Purpose |
| --- | --- | --- |
| `API_BASE_URL` | server only | Laravel API base, e.g. `https://api-staging.wegodeliva.com/api/v1` |
| `SESSION_COOKIE_NAME` | server only | Optional cookie name override |

Set them in Vercel project settings; never commit `.env.local`.
