# 0010. App-scoped Sanctum tokens; admin web keeps its token in an httpOnly cookie

Date: 2026-11-01 · Status: Accepted

## Context
Spec 02 §2.4 and spec 14 §14.1 require that each app only accepts users with a matching role, and that a token issued to one app cannot call another app's endpoints. The admin panel runs on Vercel (Next.js) on a different domain from the API.

## Decision
- Every sign-in names its app (`customer`, `vendor`, `rider`; `admin` only through `/admin/auth/login`). The backend refuses users without a matching role (`role_not_allowed`) and issues a Sanctum personal access token with exactly one ability, `app:<name>`.
- Lifetimes: 60 days for mobile apps, 12 hours for admin (env-configurable). Logout revokes the current token; password reset revokes all tokens; suspended users are blocked by middleware even with a valid token.
- Sanctum's cookie (stateful SPA) mode is switched off. The Next.js admin calls the API **server-side**: a route handler logs in, stores the token in an `httpOnly`, `SameSite=Strict`, `Secure` cookie on the admin domain, and server components forward it as a bearer token. Browser JavaScript never sees the token.
- Mobile apps keep the token in Keychain/Keystore via `flutter_secure_storage`.
- Admin TOTP (spec 02 §2.4, MUST before launch) is not built yet; the admin login is the single place it will be added.

## Consequences
- No CORS or CSRF setup between the admin domain and the API: the browser only talks to Next.js.
- One user can hold separate tokens for, say, the customer and rider apps; each works only in its own app.
- The Next.js server needs network access to the API (true on Vercel).
