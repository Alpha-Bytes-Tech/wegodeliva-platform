import type { Metadata } from "next";

import { LoginForm } from "@/components/login-form";
import styles from "./login.module.css";

export const metadata: Metadata = { title: "Sign in" };

export default function LoginPage() {
  return (
    <main className={styles.page}>
      <section className={`card ${styles.panel}`} aria-labelledby="login-title">
        <p className={styles.brand}>WeGoDeliva</p>
        <h1 id="login-title" className={styles.title}>
          Admin sign in
        </h1>
        <LoginForm />
        <p className={styles.note}>
          Two-factor sign-in (authenticator app) will be required for admins before launch.
        </p>
      </section>
    </main>
  );
}
