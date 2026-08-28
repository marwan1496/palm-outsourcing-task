import type { Metadata } from "next";

import { ProductGrid } from "@/components/products/ProductGrid";

export const metadata: Metadata = {
  title: "Products | Palm Task",
  description: "Products scraped from Jumia and Amazon, refreshed every 30 seconds.",
};

/**
 * The /products page.
 *
 * A server component that renders a static shell around one client component.
 * Only ProductGrid needs JavaScript in the browser - the heading, the layout
 * and the product cards are all rendered on the server, so the bundle stays
 * small and the page paints before any JS runs.
 */
export default function ProductsPage() {
  return (
    <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <header className="mb-8">
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white sm:text-3xl">
          Scraped Products
        </h1>
        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
          Live from the Laravel API, refreshing automatically every 30 seconds.
        </p>
      </header>

      <ProductGrid />
    </main>
  );
}
