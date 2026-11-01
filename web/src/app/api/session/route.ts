import { cookies } from "next/headers";
import { NextResponse } from "next/server";

import { GENERIC_ERROR } from "@/lib/api-error";
import { backendFetch, type AdminUser } from "@/lib/backend";
import { SESSION_COOKIE, getSessionToken, sessionCookieOptions } from "@/lib/session";

type LoginResponse = { token: string; user: AdminUser };

function errorResponse(status: number, error = GENERIC_ERROR) {
  return NextResponse.json({ error }, { status });
}

/** Log in: forwards email + password to the backend and stores the token in an httpOnly cookie. */
export async function POST(request: Request) {
  const body: unknown = await request.json().catch(() => null);
  const email = (body as { email?: unknown } | null)?.email;
  const password = (body as { password?: unknown } | null)?.password;
  if (typeof email !== "string" || typeof password !== "string") {
    return errorResponse(400, {
      code: "validation_failed",
      message: "Enter your email and password.",
      fields: {},
    });
  }

  // TODO(phase-6): TOTP second factor for admins (spec 02 §2.4). The backend will answer
  // with `totp_required` and this handler will forward the code.
  const result = await backendFetch<LoginResponse>("/admin/auth/login", {
    method: "POST",
    body: { email, password },
  });
  if (!result.ok) {
    return errorResponse(result.status, result.error);
  }

  (await cookies()).set(SESSION_COOKIE, result.data.token, sessionCookieOptions());
  const { uuid, name, roles } = result.data.user;
  // Only non-secret profile fields go back to the browser; never the token.
  return NextResponse.json({ data: { user: { uuid, name, roles } } });
}

/** Log out: revokes the token on the backend (best effort) and clears the cookie. */
export async function DELETE() {
  const token = await getSessionToken();
  if (token) {
    await backendFetch("/auth/logout", { method: "POST", token });
  }
  (await cookies()).delete(SESSION_COOKIE);
  return new NextResponse(null, { status: 204 });
}
