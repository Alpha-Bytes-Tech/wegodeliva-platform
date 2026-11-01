import type { Metadata } from "next";

export const metadata: Metadata = { title: "Dashboard" };

export default function DashboardPage() {
  return (
    <>
      <h1>Dashboard</h1>
      <div className="card">
        <p>
          Orders, revenue, live board and alerts arrive in Phase 6 (spec 12.1). This placeholder
          confirms the admin session works.
        </p>
      </div>
    </>
  );
}
