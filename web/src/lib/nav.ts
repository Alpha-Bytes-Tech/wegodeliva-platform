/** Admin sections from docs/spec/12-admin-panel.md. Only the dashboard exists in Phase 1. */
export type NavItem = { slug: string; label: string; spec: string };

export const ADMIN_NAV: readonly NavItem[] = [
  { slug: "dashboard", label: "Dashboard", spec: "12.1" },
  { slug: "people", label: "People", spec: "12.2" },
  { slug: "orders", label: "Orders", spec: "12.3" },
  { slug: "catalogue", label: "Catalogue & content", spec: "12.4" },
  { slug: "zones", label: "Cities, zones & fees", spec: "12.5" },
  { slug: "promotions", label: "Promotions", spec: "12.6" },
  { slug: "finance", label: "Finance", spec: "12.7" },
  { slug: "reports", label: "Reports", spec: "12.8" },
  { slug: "support", label: "Support & chat", spec: "12.9" },
  { slug: "settings", label: "Settings", spec: "12.10" },
  { slug: "system", label: "System & audit log", spec: "12.11" },
] as const;

export function findSection(slug: string): NavItem | undefined {
  return ADMIN_NAV.find((item) => item.slug === slug);
}
