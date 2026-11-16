import "server-only";

import { backendFetch, isAdmin, type AdminUser } from "./backend";
import { getSessionToken } from "./session";

/** Returns the signed-in admin, or null when there is no valid admin session. */
export async function getCurrentAdmin(): Promise<AdminUser | null> {
  const token = await getSessionToken();
  if (!token) return null;
  const result = await backendFetch<AdminUser>("/auth/me", { token });
  if (!result.ok || !isAdmin(result.data)) return null;
  return result.data;
}
