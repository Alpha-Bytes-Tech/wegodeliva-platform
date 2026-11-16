import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { findSection } from "@/lib/nav";

export async function generateMetadata({ params }: PageProps<"/[section]">): Promise<Metadata> {
  const { section } = await params;
  return { title: findSection(section)?.label ?? "Not found" };
}

export default async function SectionPage({ params }: PageProps<"/[section]">) {
  const { section } = await params;
  const item = findSection(section);
  if (!item) notFound();

  return (
    <>
      <h1>{item.label}</h1>
      <div className="card">
        <p>Not built yet. See docs/spec/12-admin-panel.md §{item.spec}.</p>
      </div>
    </>
  );
}
