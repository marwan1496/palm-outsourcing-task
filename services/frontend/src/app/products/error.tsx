"use client";

import { useEffect } from "react";

/**
 * Error boundary for the /products route.
 *
 * ProductGrid already handles fetch failures gracefully, so this catches the
 * rarer case: a rendering error. Without it, Next.js shows a bare error page.
 *
 * Must be a client component - error boundaries rely on React state.
 */
export default function Error({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    // In production this is where a reporting service would be called.
    console.error("Products page error:", error);
  }, [error]);

  return (
    <main className="mx-auto max-w-2xl px-4 py-16 text-center">
      <h1 className="text-xl font-semibold text-slate-900 dark:text-white">
        Something went wrong
      </h1>

      <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
        {error.message || "An unexpected error occurred while loading products."}
      </p>

      <button
        type="button"
        onClick={reset}
        className="mt-6 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200"
      >
        Try again
      </button>
    </main>
  );
}
