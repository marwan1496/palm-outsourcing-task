import { ProductGridSkeleton } from "@/components/products/ProductGridSkeleton";

/**
 * Rendered by Next.js while the /products route segment loads.
 *
 * Mirrors the real page's layout exactly, so the transition to loaded content
 * involves no layout shift.
 */
export default function Loading() {
  return (
    <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <header className="mb-8">
        <div className="h-8 w-64 animate-pulse rounded bg-slate-200 dark:bg-slate-800" />
        <div className="mt-2 h-4 w-96 max-w-full animate-pulse rounded bg-slate-200 dark:bg-slate-800" />
      </header>

      <ProductGridSkeleton />
    </main>
  );
}
