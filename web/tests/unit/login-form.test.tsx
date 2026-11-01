import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { LoginForm } from "@/components/login-form";

const replace = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, refresh: vi.fn() }),
}));

describe("LoginForm", () => {
  beforeEach(() => {
    replace.mockReset();
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("posts credentials to the session route and goes to the dashboard", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response("{}", { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);
    render(<LoginForm />);

    await userEvent.type(screen.getByLabelText("Email"), "admin@example.com");
    await userEvent.type(screen.getByLabelText("Password"), "secret-password");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/session",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ email: "admin@example.com", password: "secret-password" }),
      }),
    );
    expect(replace).toHaveBeenCalledWith("/dashboard");
  });

  it("shows the API error message on failure", async () => {
    const body = { error: { code: "invalid_credentials", message: "Wrong email or password." } };
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status: 401 })),
    );
    render(<LoginForm />);

    await userEvent.type(screen.getByLabelText("Email"), "admin@example.com");
    await userEvent.type(screen.getByLabelText("Password"), "nope");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Wrong email or password.");
    expect(replace).not.toHaveBeenCalled();
  });
});
