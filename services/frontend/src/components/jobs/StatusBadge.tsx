import type { ScrapeJobStatus } from "@/lib/api/schemas";

/**
 * Colour and wording per status.
 *
 * Colour alone would fail anyone who can't distinguish red from green, so the
 * badge always carries the word too.
 */
const STYLES: Record<ScrapeJobStatus, string> = {
  pending:
    "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
  running:
    "bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300",
  completed:
    "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300",
  failed:
    "bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300",
};

export function StatusBadge({
  status,
  label,
}: {
  status: ScrapeJobStatus;
  label: string;
}) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ${STYLES[status]}`}
    >
      {status === "running" && (
        <span
          className="h-1.5 w-1.5 animate-pulse rounded-full bg-current"
          aria-hidden="true"
        />
      )}
      {label}
    </span>
  );
}
