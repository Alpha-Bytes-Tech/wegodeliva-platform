import "server-only";

import { cookies } from "next/headers";

/**
 * The Sanctum token lives only in an httpOnly cookie. Client-side JavaScript never sees it:
 * the browser sends it to our own route handlers and server components, which forward it to
 * the Laravel API.
 */
export const SESSION_COOKIE = process.env.SESSION_COOKIE_NAME ?? "wgd_admin_session";

/** Matches the lifetime the backend gives admin tokens (12 hours). */
export const SESSION_MAX_AGE_SECONDS = 60 * 60 * 12;

export function sessionCookieOptions() {
  return {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "strict" as const,
    path: "/",
    maxAge: SESSION_MAX_AGE_SECONDS,
  };
}

export async function getSessionToken(): Promise<string | undefined> {
  return (await cookies()).get(SESSION_COOKIE)?.value;
}
