"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { parseApiError, type ApiError } from "@/lib/api-error";

export function LoginForm() {
  const router = useRouter();
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setSubmitting(true);
    setError(null);
    try {
      const response = await fetch("/api/session", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: form.get("email"), password: form.get("password") }),
      });
      if (!response.ok) {
        setError(parseApiError(await response.json().catch(() => null)));
        return;
      }
      router.replace("/dashboard");
      router.refresh();
    } catch {
      setError(parseApiError(null));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate style={{ display: "grid", gap: "var(--space-4)" }}>
      {error && (
        <div role="alert" className="alert-error">
          {error.message}
        </div>
      )}
      <div className="field">
        <label htmlFor="email">Email</label>
        <input id="email" name="email" type="email" autoComplete="username" required />
        {error?.fields.email?.map((msg) => (
          <small key={msg} className="alert-error">
            {msg}
          </small>
        ))}
      </div>
      <div className="field">
        <label htmlFor="password">Password</label>
        <input
          id="password"
          name="password"
          type="password"
          autoComplete="current-password"
          required
        />
      </div>
      <button type="submit" className="btn-primary" disabled={submitting}>
        {submitting ? "Signing in…" : "Sign in"}
      </button>
    </form>
  );
}
