import type { Metadata } from "next";

import { JobList } from "@/components/jobs/JobList";
import { ScrapeForm } from "@/components/jobs/ScrapeForm";

export const metadata: Metadata = {
  title: "Scrape Jobs | Palm Task",
  description: "Submit product URLs and watch them being scraped.",
};

/**
 * The /jobs page: submit URLs, watch them run.
 *
 * This is the quickest way to see the scraper actually working. Paste a few
 * URLs, hit Scrape, and the rows move from pending to running to completed
 * without touching a terminal.
 */
export default function JobsPage() {
  return (
    <main className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
      <header className="mb-6">
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white sm:text-3xl">
          Scrape Jobs
        </h1>
        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
          Queue product pages and watch them run. The list refreshes on its own.
        </p>
      </header>

      <div className="space-y-6">
        <ScrapeForm />
        <JobList />
      </div>

      <p className="mt-8 rounded-lg bg-slate-100 p-3 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-400">
        Jobs only move while a queue worker is running. <code>npm run dev</code>{" "}
        starts one for you; on its own, <code>php artisan queue:work</code> does the
        same.
      </p>
    </main>
  );
}
