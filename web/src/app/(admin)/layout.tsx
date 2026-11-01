import Link from "next/link";
import { redirect } from "next/navigation";

import { SignOutButton } from "@/components/sign-out-button";
import { getCurrentAdmin } from "@/lib/auth";
import { ADMIN_NAV } from "@/lib/nav";
import styles from "./admin.module.css";

export default async function AdminLayout({ children }: LayoutProps<"/">) {
  const admin = await getCurrentAdmin();
  if (!admin) {
    redirect("/login");
  }

  return (
    <div className={styles.shell}>
      <nav className={styles.sidebar} aria-label="Admin sections">
        <p className={styles.brand}>WeGoDeliva</p>
        <ul className={styles.nav}>
          {ADMIN_NAV.map((item) => (
            <li key={item.slug}>
              <Link href={`/${item.slug}`} className={styles.link}>
                {item.label}
              </Link>
            </li>
          ))}
        </ul>
      </nav>
      <div className={styles.main}>
        <header className={styles.header}>
          <span>
            Signed in as <strong>{admin.name}</strong>
          </span>
          <SignOutButton />
        </header>
        <main className={styles.content}>{children}</main>
      </div>
    </div>
  );
}
