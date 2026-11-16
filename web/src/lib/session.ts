import "server-only";

import { cookies } from "next/headers";

/**
 * The Sanctum token lives only in an httpOnly cookie. Client-side JavaScript never sees it:
 * the browser sends it to our own route handlers and server components, which forward it to
 * the Laravel API.
 */
export const SESSION_COOKIE = process.env.SESSION_COOKIE_NAME ?? "wgd_admin_session";

/**
 * Used only when the login response has no usable `expires_at`. Kept short on purpose: the
 * cookie must never outlive the token it carries by much.
 */
export const SESSION_FALLBACK_MAX_AGE_SECONDS = 60 * 60;

/**
 * Seconds until the backend token expires, from the login response's `expires_at` (ISO 8601).
 * Falls back to {@link SESSION_FALLBACK_MAX_AGE_SECONDS} when it is missing or unparseable.
 */
export function sessionMaxAgeSeconds(expiresAt: unknown, now: number = Date.now()): number {
  const expiry = typeof expiresAt === "string" ? Date.parse(expiresAt) : Number.NaN;
  if (Number.isNaN(expiry)) {
    return SESSION_FALLBACK_MAX_AGE_SECONDS;
  }
  return Math.max(0, Math.floor((expiry - now) / 1000));
}

/** Cookie options; the cookie expires when the backend token does. */
export function sessionCookieOptions(expiresAt?: unknown) {
  return {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "strict" as const,
    path: "/",
    maxAge: sessionMaxAgeSeconds(expiresAt),
  };
}

export async function getSessionToken(): Promise<string | undefined> {
  return (await cookies()).get(SESSION_COOKIE)?.value;
}
