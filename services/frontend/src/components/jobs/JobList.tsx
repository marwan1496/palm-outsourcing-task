"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { scrapeJobListSchema, type ScrapeJob, type ScrapeJobList } from "@/lib/api/schemas";
import { formatRelativeTime, truncate } from "@/lib/format";

import { StatusBadge } from "./StatusBadge";

/**
 * How often to check for progress while jobs are still moving.
 *
 * Jobs change far faster than products do, so this is much tighter than the
 * products page's 30 seconds.
 */
export const ACTIVE_POLL_MS = 3_000;

/**
 * How often to check once everything has finished.
 *
 * Polling every three seconds forever would hammer the API to watch a list
 * that cannot change without someone submitting new work.
 */
export const IDLE_POLL_MS = 15_000;

async function fetchJobs(): Promise<ScrapeJobList> {
  const response = await fetch("/api/jobs", {
    headers: { Accept: "application/json" },
  });

  if (!response.ok) {
    const body = await response.json().catch(() => null);
    throw new Error(body?.message ?? `Request failed with ${response.status}`);
  }

  return scrapeJobListSchema.parse(await response.json());
}

/**
 * The live list of scrape jobs.
 */
export function JobList() {
  const queryClient = useQueryClient();

  const { data, error, isPending, refetch } = useQuery({
    queryKey: ["scrape-jobs"],
    queryFn: fetchJobs,

    // Poll fast while work is in flight, slowly once it has settled. The
    // unfinished count comes from the API and covers every page, not just the
    // one being displayed.
    refetchInterval: (query) =>
      (query.state.data?.meta.unfinished ?? 0) > 0 ? ACTIVE_POLL_MS : IDLE_POLL_MS,
    refetchIntervalInBackground: true,
    placeholderData: keepPreviousData,
  });

  const retry = useMutation({
    mutationFn: async (id: number) => {
      const response = await fetch(`/api/jobs/${id}/retry`, { method: "POST" });

      if (!response.ok) {
        const body = await response.json().catch(() => null);
        throw new Error(body?.message ?? "Could not retry the job.");
      }
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["scrape-jobs"] }),
  });

  if (isPending) return <JobListSkeleton />;

  if (error) {
    return (
      <div
        role="alert"
        className="rounded-xl border border-red-200 bg-red-50 p-6 text-center dark:border-red-900/50 dark:bg-red-950/30"
      >
        <p className="font-medium text-red-900 dark:text-red-200">
          Could not load jobs
        </p>
        <p className="mt-1 text-sm text-red-700 dark:text-red-300">{error.message}</p>
        <button
          type="button"
          onClick={() => refetch()}
          className="mt-4 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
        >
          Try again
        </button>
      </div>
    );
  }

  if (data.data.length === 0) {
    return (
      <div className="rounded-xl border border-dashed border-slate-300 p-12 text-center dark:border-slate-700">
        <p className="font-medium text-slate-900 dark:text-slate-100">No jobs yet</p>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Paste some product URLs above and hit Scrape.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between text-sm text-slate-500 dark:text-slate-400">
        <p>
          <span className="font-medium text-slate-900 dark:text-slate-100">
            {data.meta.total}
          </span>{" "}
          job{data.meta.total === 1 ? "" : "s"}
        </p>

        <p aria-live="polite">
          {data.meta.unfinished > 0
            ? `${data.meta.unfinished} in progress`
            : "All finished"}
        </p>
      </div>

      <ul className="space-y-2">
        {data.data.map((job) => (
          <JobRow
            key={job.id}
            job={job}
            onRetry={() => retry.mutate(job.id)}
            isRetrying={retry.isPending && retry.variables === job.id}
          />
        ))}
      </ul>
    </div>
  );
}

/**
 * One job. Shows the URL, where it got to, and what it produced or why it didn't.
 */
function JobRow({
  job,
  onRetry,
  isRetrying,
}: {
  job: ScrapeJob;
  onRetry: () => void;
  isRetrying: boolean;
}) {
  return (
    <li className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p
            className="truncate font-mono text-xs text-slate-700 dark:text-slate-300"
            title={job.url}
          >
            {job.url}
          </p>

          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            {formatRelativeTime(new Date(job.created_at))}
            {job.duration_ms !== null && ` · took ${(job.duration_ms / 1000).toFixed(1)}s`}
            {job.attempts > 1 && ` · ${job.attempts} attempts`}
          </p>
        </div>

        <div className="flex shrink-0 items-center gap-2">
          <StatusBadge
            status={job.status}
            label={job.status_label}
            isAwaitingRetry={job.is_awaiting_retry}
          />

          {job.is_retryable && (
            <button
              type="button"
              onClick={onRetry}
              disabled={isRetrying}
              className="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              {isRetrying ? "Retrying…" : "Retry"}
            </button>
          )}
        </div>
      </div>

      {job.product && (
        <p className="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-900 dark:border-slate-800 dark:text-slate-100">
          {truncate(job.product.title, 70)}{" "}
          <span className="font-medium">{job.product.price_formatted}</span>
        </p>
      )}

      {job.error && (
        // Amber while a retry is still coming, red once it has genuinely
        // failed. The same message means different things depending on
        // whether the queue has given up.
        <p
          className={`mt-3 border-t border-slate-100 pt-3 text-xs dark:border-slate-800 ${
            job.is_awaiting_retry
              ? "text-amber-700 dark:text-amber-400"
              : "text-red-600 dark:text-red-400"
          }`}
        >
          {job.is_awaiting_retry && <span className="font-medium">Will retry — </span>}
          {job.error}
        </p>
      )}
    </li>
  );
}

function JobListSkeleton() {
  return (
    <ul className="space-y-2" aria-busy="true" aria-label="Loading jobs">
      {Array.from({ length: 3 }).map((_, index) => (
        <li
          key={index}
          className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"
        >
          <div className="h-3 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800" />
          <div className="mt-2 h-3 w-1/4 animate-pulse rounded bg-slate-200 dark:bg-slate-800" />
        </li>
      ))}
    </ul>
  );
}
