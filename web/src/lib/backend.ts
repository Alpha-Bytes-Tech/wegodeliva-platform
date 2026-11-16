import "server-only";

import { GENERIC_ERROR, parseApiError, type ApiError } from "./api-error";

export type AdminUser = {
  uuid: string;
  name: string;
  email: string;
  roles: string[];
};

export type BackendResult<T> =
  { ok: true; data: T } | { ok: false; status: number; error: ApiError };

function baseUrl(): string {
  return (process.env.API_BASE_URL ?? "http://localhost:8000/api/v1").replace(/\/+$/, "");
}

/** Calls the Laravel API from the server. Network failures become a generic 502 error. */
export async function backendFetch<T>(
  path: string,
  init: { method?: string; body?: unknown; token?: string } = {},
): Promise<BackendResult<T>> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (init.body !== undefined) headers["Content-Type"] = "application/json";
  if (init.token) headers.Authorization = `Bearer ${init.token}`;

  let response: Response;
  try {
    response = await fetch(`${baseUrl()}${path}`, {
      method: init.method ?? "GET",
      headers,
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
      cache: "no-store",
    });
  } catch {
    return { ok: false, status: 502, error: GENERIC_ERROR };
  }

  const body: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    return { ok: false, status: response.status, error: parseApiError(body) };
  }
  const data = (body as { data?: T } | null)?.data;
  if (data === undefined) {
    return { ok: false, status: 502, error: GENERIC_ERROR };
  }
  return { ok: true, data };
}

export function isAdmin(user: AdminUser): boolean {
  return user.roles.some((role) => ["admin", "super_admin", "zone_admin"].includes(role));
}
