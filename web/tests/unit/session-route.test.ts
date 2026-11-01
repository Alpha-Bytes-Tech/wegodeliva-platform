import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const store = new Map<string, { value: string; options?: Record<string, unknown> }>();
vi.mock("next/headers", () => ({
  cookies: async () => ({
    get: (name: string) => (store.has(name) ? { name, value: store.get(name)!.value } : undefined),
    set: (name: string, value: string, options?: Record<string, unknown>) =>
      store.set(name, { value, options }),
    delete: (name: string) => store.delete(name),
  }),
}));

import { DELETE, POST } from "@/app/api/session/route";

function jsonRequest(body: unknown) {
  return new Request("http://localhost/api/session", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
}

describe("/api/session", () => {
  beforeEach(() => {
    store.clear();
    process.env.API_BASE_URL = "http://backend.test/api/v1";
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("stores the token in an httpOnly cookie and never returns it", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            token: "1|secret-token",
            user: { uuid: "u-1", name: "Ada", email: "a@x.test", roles: ["super_admin"] },
          },
        }),
        { status: 200 },
      ),
    );
    vi.stubGlobal("fetch", fetchMock);

    const response = await POST(jsonRequest({ email: "a@x.test", password: "pw" }));
    const text = await response.text();

    expect(response.status).toBe(200);
    expect(text).not.toContain("secret-token");
    expect(fetchMock.mock.calls[0][0]).toBe("http://backend.test/api/v1/admin/auth/login");
    const cookie = store.get("wgd_admin_session");
    expect(cookie?.value).toBe("1|secret-token");
    expect(cookie?.options).toMatchObject({ httpOnly: true, sameSite: "strict", path: "/" });
  });

  it("passes backend errors through without setting a cookie", async () => {
    const error = { code: "invalid_credentials", message: "Wrong email or password.", fields: {} };
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(new Response(JSON.stringify({ error }), { status: 401 })),
    );

    const response = await POST(jsonRequest({ email: "a@x.test", password: "bad" }));

    expect(response.status).toBe(401);
    expect(await response.json()).toEqual({ error });
    expect(store.size).toBe(0);
  });

  it("rejects malformed bodies", async () => {
    const response = await POST(jsonRequest({ email: 1 }));
    expect(response.status).toBe(400);
  });

  it("returns a generic error when the backend is unreachable", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("ECONNREFUSED")));
    const response = await POST(jsonRequest({ email: "a@x.test", password: "pw" }));
    expect(response.status).toBe(502);
    expect((await response.json()).error.code).toBe("server_error");
  });

  it("logs out: revokes the token and clears the cookie", async () => {
    store.set("wgd_admin_session", { value: "1|secret-token" });
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal("fetch", fetchMock);

    const response = await DELETE();

    expect(response.status).toBe(204);
    expect(fetchMock.mock.calls[0][0]).toBe("http://backend.test/api/v1/auth/logout");
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe("Bearer 1|secret-token");
    expect(store.has("wgd_admin_session")).toBe(false);
  });
});
