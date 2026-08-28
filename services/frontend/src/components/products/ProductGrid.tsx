"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { productListSchema, type ProductList } from "@/lib/api/schemas";
import { formatRelativeTime } from "@/lib/format";

import { ProductCard } from "./ProductCard";
import { ProductGridSkeleton } from "./ProductGridSkeleton";

/**
 * How often to refresh, in milliseconds. The brief asks for 30 seconds, and
 * the backend's cache is tuned to match so a poll usually lands on warm cache.
 */
export const REFRESH_INTERVAL_MS = 30_000;

/**
 * Fetch products through the BFF route.
 *
 * Note the URL: /api/products is THIS Next.js app, not Laravel. The browser
 * has no credentials and no knowledge of where the backend lives.
 */
async function fetchProducts(): Promise<ProductList> {
  const response = await fetch("/api/products", {
    headers: { Accept: "application/json" },
  });

  if (!response.ok) {
    const body = await response.json().catch(() => null);
    throw new Error(body?.message ?? `Request failed with ${response.status}`);
  }

  // Validated again on this side of the boundary. The BFF is trusted, but
  // parsing here means a component can rely on the shape absolutely.
  return productListSchema.parse(await response.json());
}

/**
 * The product grid, refreshing itself every 30 seconds.
 */
export function ProductGrid() {
  const { data, error, isPending, isFetching, dataUpdatedAt, refetch } =
    useQuery({
      queryKey: ["products"],
      queryFn: fetchProducts,

      // The requirement from the brief.
      refetchInterval: REFRESH_INTERVAL_MS,

      // Keep polling while the tab is in the background, so a screen left open
      // during a demo is current when you switch back to it.
      refetchIntervalInBackground: true,

      // Without this the grid would unmount and show the skeleton on every
      // refresh - a full flash of empty boxes twice a minute. Instead the old
      // data stays visible until the new data replaces it.
      placeholderData: keepPreviousData,
    });

  if (isPending) return <ProductGridSkeleton />;
  if (error) return <ErrorState message={error.message} onRetry={() => refetch()} />;
  if (data.data.length === 0) return <EmptyState />;

  return (
    <div className="space-y-4">
      <StatusBar
        total={data.meta.total}
        updatedAt={dataUpdatedAt}
        isRefreshing={isFetching}
      />

      <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
        {data.data.map((product, index) => (
          <ProductCard
            key={product.id}
            product={product}
            // The first row is above the fold on every breakpoint (4 columns
            // at the widest), so those images load eagerly to improve the
            // Largest Contentful Paint. The rest stay lazy.
            priority={index < 4}
          />
        ))}
      </div>
    </div>
  );
}

/**
 * Shows the product count and how long ago the data was refreshed.
 *
 * The "updated Ns ago" label is what makes the 30-second refresh visible -
 * without it, the polling is invisible and has to be taken on trust.
 */
function StatusBar({
  total,
  updatedAt,
  isRefreshing,
}: {
  total: number;
  updatedAt: number;
  isRefreshing: boolean;
}) {
  const label = useRelativeTime(updatedAt);

  return (
    <div className="flex items-center justify-between text-sm text-slate-500 dark:text-slate-400">
      <p>
        <span className="font-medium text-slate-900 dark:text-slate-100">
          {total}
        </span>{" "}
        {total === 1 ? "product" : "products"}
      </p>

      <p className="flex items-center gap-2" aria-live="polite">
        {isRefreshing && (
          <span
            className="inline-block h-2 w-2 animate-pulse rounded-full bg-emerald-500"
            aria-hidden="true"
          />
        )}
        <span>Updated {label}</span>
      </p>
    </div>
  );
}

/**
 * Re-render once a second so the relative timestamp actually counts up.
 *
 * TanStack Query only re-renders when data changes, so without this ticker the
 * label would freeze at "just now" until the next successful fetch.
 */
function useRelativeTime(timestamp: number): string {
  const [label, setLabel] = useState(() =>
    formatRelativeTime(new Date(timestamp)),
  );

  useEffect(() => {
    setLabel(formatRelativeTime(new Date(timestamp)));

    const id = setInterval(
      () => setLabel(formatRelativeTime(new Date(timestamp))),
      1000,
    );

    return () => clearInterval(id);
  }, [timestamp]);

  return label;
}

/** Shown when the request fails. */
function ErrorState({
  message,
  onRetry,
}: {
  message: string;
  onRetry: () => void;
}) {
  return (
    <div
      role="alert"
      className="rounded-xl border border-red-200 bg-red-50 p-6 text-center dark:border-red-900/50 dark:bg-red-950/30"
    >
      <h2 className="font-medium text-red-900 dark:text-red-200">
        Could not load products
      </h2>
      <p className="mt-1 text-sm text-red-700 dark:text-red-300">{message}</p>

      <button
        type="button"
        onClick={onRetry}
        className="mt-4 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700"
      >
        Try again
      </button>
    </div>
  );
}

/** Shown when the API works but there is nothing stored yet. */
function EmptyState() {
  return (
    <div className="rounded-xl border border-dashed border-slate-300 p-12 text-center dark:border-slate-700">
      <h2 className="font-medium text-slate-900 dark:text-slate-100">
        No products yet
      </h2>
      <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
        Scrape one from the backend to get started:
      </p>
      <code className="mt-3 inline-block rounded-lg bg-slate-100 px-3 py-1.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-300">
        php artisan products:scrape &lt;url&gt;
      </code>
    </div>
  );
}
