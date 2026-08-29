"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

const LINKS = [
  { href: "/products", label: "Products" },
  { href: "/jobs", label: "Scrape Jobs" },
] as const;

/**
 * Top navigation.
 *
 * A client component only because it highlights the current page, which needs
 * to know the pathname.
 */
export function SiteNav() {
  const pathname = usePathname();

  return (
    <nav className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
      <div className="mx-auto flex max-w-7xl items-center gap-1 px-4 sm:px-6 lg:px-8">
        <span className="mr-4 py-3 text-sm font-semibold text-slate-900 dark:text-white">
          Palm Task
        </span>

        {LINKS.map((link) => {
          const isActive = pathname === link.href;

          return (
            <Link
              key={link.href}
              href={link.href}
              aria-current={isActive ? "page" : undefined}
              className={`border-b-2 px-3 py-3 text-sm transition ${
                isActive
                  ? "border-slate-900 font-medium text-slate-900 dark:border-white dark:text-white"
                  : "border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
              }`}
            >
              {link.label}
            </Link>
          );
        })}
      </div>
    </nav>
  );
}
