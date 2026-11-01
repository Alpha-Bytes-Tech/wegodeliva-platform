import { describe, expect, it } from "vitest";

import { GENERIC_ERROR, parseApiError } from "@/lib/api-error";

describe("parseApiError", () => {
  it("reads the spec 14 error envelope", () => {
    const error = parseApiError({
      error: { code: "validation_failed", message: "Check the form.", fields: { email: ["Bad"] } },
    });
    expect(error).toEqual({
      code: "validation_failed",
      message: "Check the form.",
      fields: { email: ["Bad"] },
    });
  });

  it("falls back to a generic error for anything else", () => {
    expect(parseApiError(null)).toEqual(GENERIC_ERROR);
    expect(parseApiError("oops")).toEqual(GENERIC_ERROR);
    expect(parseApiError({ message: "Server Error" })).toEqual(GENERIC_ERROR);
  });

  it("drops malformed field entries", () => {
    const error = parseApiError({ error: { code: 1, fields: { a: "x", b: ["ok", 2] } } });
    expect(error.code).toBe("server_error");
    expect(error.fields).toEqual({ b: ["ok"] });
  });
});
