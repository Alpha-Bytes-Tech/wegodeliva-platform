"use client";

import { useRouter } from "next/navigation";

export function SignOutButton() {
  const router = useRouter();
  return (
    <button
      type="button"
      className="btn-ghost"
      onClick={async () => {
        await fetch("/api/session", { method: "DELETE" }).catch(() => undefined);
        router.replace("/login");
        router.refresh();
      }}
    >
      Sign out
    </button>
  );
}
