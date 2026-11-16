import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: { default: "WeGoDeliva Admin", template: "%s · WeGoDeliva Admin" },
  description: "WeGoDeliva operations panel",
  robots: { index: false, follow: false },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
