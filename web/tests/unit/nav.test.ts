import { describe, expect, it } from "vitest";

import { ADMIN_NAV, findSection } from "@/lib/nav";

describe("admin navigation", () => {
  it("lists every spec 12 section once", () => {
    const slugs = ADMIN_NAV.map((item) => item.slug);
    expect(new Set(slugs).size).toBe(slugs.length);
    expect(slugs[0]).toBe("dashboard");
    expect(ADMIN_NAV).toHaveLength(11);
  });

  it("finds sections by slug", () => {
    expect(findSection("finance")?.spec).toBe("12.7");
    expect(findSection("nope")).toBeUndefined();
  });
});
