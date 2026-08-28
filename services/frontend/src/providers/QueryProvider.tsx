"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState, type ReactNode } from "react";

/**
 * Provides the TanStack Query client to the app.
 *
 * The client is created inside useState rather than as a module-level constant.
 * That matters on the server: a module-level client would be shared between
 * every concurrent request, so one user's data could be served to another.
 * Creating it per component instance gives each request its own cache.
 */
export function QueryProvider({ children }: { children: ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            // Matches the 30-second poll: data is considered fresh for just
            // under one interval, so a remount does not trigger a redundant
            // fetch moments before the scheduled one.
            staleTime: 25_000,

            // The BFF route surfaces real failures (backend down, bad token).
            // One retry covers a transient blip without making a genuinely
            // broken state take ages to appear.
            retry: 1,

            refetchOnWindowFocus: true,
          },
        },
      }),
  );

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
