"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";

import type { RejectedUrl } from "@/lib/api/schemas";

/** Matches the backend's cap, so an over-long list is caught before a round trip. */
const MAX_URLS = 10;

/**
 * Paste URLs, one per line, and queue them.
 *
 * The batch endpoint lets some URLs succeed while others are rejected, so this
 * has to show both outcomes at once. A form that only said "failed" when one
 * line out of ten had a typo would be worse than useless.
 */
export function ScrapeForm() {
  const [text, setText] = useState("");
  const [rejected, setRejected] = useState<RejectedUrl[]>([]);
  const queryClient = useQueryClient();

  const urls = parseUrls(text);
  const tooMany = urls.length > MAX_URLS;

  const submit = useMutation({
    mutationFn: async (urls: string[]) => {
      const response = await fetch("/api/jobs", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ urls }),
      });

      const body = await response.json().catch(() => null);

      if (!response.ok) {
        // A 422 carries per-URL reasons; show them rather than a bare message.
        setRejected(body?.rejected ?? []);
        throw new Error(body?.message ?? `Request failed with ${response.status}`);
      }

      return body as { accepted: unknown[]; rejected: RejectedUrl[] };
    },
    onSuccess: (result) => {
      setRejected(result.rejected ?? []);

      // Clear only the lines that were accepted, so a rejected URL stays in
      // the box to be corrected instead of being silently thrown away.
      const rejectedUrls = new Set((result.rejected ?? []).map((r) => r.url));
      setText(urls.filter((url) => rejectedUrls.has(url)).join("\n"));

      // Show the new jobs immediately rather than waiting for the next poll.
      void queryClient.invalidateQueries({ queryKey: ["scrape-jobs"] });
    },
  });

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        if (urls.length > 0 && !tooMany) submit.mutate(urls);
      }}
      className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"
    >
      <label
        htmlFor="urls"
        className="block text-sm font-medium text-slate-900 dark:text-slate-100"
      >
        Product URLs
      </label>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        One per line, up to {MAX_URLS}. Jumia and Amazon are supported.
      </p>

      <textarea
        id="urls"
        rows={4}
        value={text}
        onChange={(event) => setText(event.target.value)}
        placeholder={"https://www.jumia.com.eg/some-product.html\nhttps://www.amazon.eg/dp/B01LR8CIRC"}
        className="mt-3 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 font-mono text-xs text-slate-900 placeholder:text-slate-400 focus:border-slate-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
      />

      <div className="mt-3 flex items-center justify-between gap-3">
        <p className="text-xs text-slate-500 dark:text-slate-400">
          {urls.length === 0
            ? "No URLs entered"
            : `${urls.length} URL${urls.length === 1 ? "" : "s"}`}
          {tooMany && (
            <span className="ml-1 text-red-600 dark:text-red-400">
              — {MAX_URLS} maximum
            </span>
          )}
        </p>

        <button
          type="submit"
          disabled={urls.length === 0 || tooMany || submit.isPending}
          className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200"
        >
          {submit.isPending ? "Queueing…" : "Scrape"}
        </button>
      </div>

      {submit.isError && rejected.length === 0 && (
        <p role="alert" className="mt-3 text-sm text-red-600 dark:text-red-400">
          {submit.error.message}
        </p>
      )}

      {rejected.length > 0 && (
        <div
          role="alert"
          className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30"
        >
          <p className="text-xs font-medium text-amber-900 dark:text-amber-200">
            {rejected.length} URL{rejected.length === 1 ? " was" : "s were"} rejected
          </p>
          <ul className="mt-2 space-y-1">
            {rejected.map((item) => (
              <li key={item.url} className="text-xs text-amber-800 dark:text-amber-300">
                <span className="font-mono break-all">{item.url}</span>
                <span className="mx-1">—</span>
                <span>{item.reason}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </form>
  );
}

/**
 * Split the textarea into URLs, one per line, ignoring blanks and duplicates.
 */
export function parseUrls(text: string): string[] {
  const seen = new Set<string>();

  return text
    .split(/[\r\n]+/)
    .map((line) => line.trim())
    .filter((line) => {
      if (line === "" || seen.has(line)) return false;
      seen.add(line);
      return true;
    });
}
