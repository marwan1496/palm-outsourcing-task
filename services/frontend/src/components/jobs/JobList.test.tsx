import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { ScrapeJob } from "@/lib/api/schemas";

import { ACTIVE_POLL_MS, IDLE_POLL_MS, JobList } from "./JobList";

function job(overrides: Partial<ScrapeJob> = {}): ScrapeJob {
  return {
    id: 1,
    batch_id: "3f2504e0-4f89-11d3-9a0c-0305e82c3301",
    url: "https://www.jumia.com.eg/product.html",
    status: "pending",
    status_label: "Pending",
    is_terminal: false,
    is_retryable: false,
    error: null,
    attempts: 0,
    duration_ms: null,
    product: null,
    started_at: null,
    finished_at: null,
    created_at: "2026-08-29T12:00:00+00:00",
    ...overrides,
  };
}

function listResponse(jobs: ScrapeJob[], unfinished?: number) {
  return {
    data: jobs,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 30,
      total: jobs.length,
      unfinished: unfinished ?? jobs.filter((j) => !j.is_terminal).length,
    },
  };
}

function Wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

const renderList = () => render(<JobList />, { wrapper: Wrapper });

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn());
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

function mockOnce(body: unknown, ok = true, status = 200) {
  (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
    ok,
    status,
    json: async () => body,
  });
}

function mockAlways(body: unknown) {
  (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
    ok: true,
    status: 200,
    json: async () => body,
  });
}

describe("rendering jobs", () => {
  it("shows a skeleton while loading", () => {
    (globalThis.fetch as ReturnType<typeof vi.fn>).mockReturnValue(new Promise(() => {}));

    renderList();

    expect(screen.getByLabelText("Loading jobs")).toBeInTheDocument();
  });

  it("lists jobs with their status", async () => {
    mockOnce(listResponse([job({ status: "completed", status_label: "Completed", is_terminal: true })]));

    renderList();

    expect(await screen.findByText("https://www.jumia.com.eg/product.html")).toBeInTheDocument();
    expect(screen.getByText("Completed")).toBeInTheDocument();
  });

  it("shows an empty state rather than a blank page", async () => {
    mockOnce(listResponse([]));

    renderList();

    expect(await screen.findByText("No jobs yet")).toBeInTheDocument();
  });

  it("shows the product once a job produced one", async () => {
    mockOnce(
      listResponse([
        job({
          status: "completed",
          status_label: "Completed",
          is_terminal: true,
          product: {
            id: 1,
            title: "Samsung Galaxy A55",
            price: 1849900,
            price_formatted: "EGP 18,499.00",
            currency: "EGP",
            image_url: null,
            source: "jumia",
            source_label: "Jumia",
            source_url: "https://www.jumia.com.eg/product.html",
            scraped_at: "2026-08-29T12:00:00+00:00",
            created_at: "2026-08-29T12:00:00+00:00",
          },
        }),
      ]),
    );

    renderList();

    expect(await screen.findByText(/Samsung Galaxy A55/)).toBeInTheDocument();
    expect(screen.getByText("EGP 18,499.00")).toBeInTheDocument();
  });

  it("shows why a job failed", async () => {
    mockOnce(
      listResponse([
        job({
          status: "failed",
          status_label: "Failed",
          is_terminal: true,
          is_retryable: true,
          error: "Blocked: Cloudflare challenge page.",
        }),
      ]),
    );

    renderList();

    expect(await screen.findByText("Blocked: Cloudflare challenge page.")).toBeInTheDocument();
  });

  it("reports how many jobs are still in progress", async () => {
    mockOnce(listResponse([job(), job({ id: 2 })], 2));

    renderList();

    expect(await screen.findByText("2 in progress")).toBeInTheDocument();
  });

  it("says so when everything has finished", async () => {
    mockOnce(listResponse([job({ status: "completed", is_terminal: true })], 0));

    renderList();

    expect(await screen.findByText("All finished")).toBeInTheDocument();
  });
});

describe("adaptive polling", () => {
  // Fast while work is moving, slow once it settles. Polling every three
  // seconds forever would hammer the API to watch a list that cannot change.
  it("polls every 3 seconds while jobs are unfinished", async () => {
    vi.useFakeTimers();

    try {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>;
      mockAlways(listResponse([job()], 1));

      renderList();
      await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

      await act(() => vi.advanceTimersByTimeAsync(ACTIVE_POLL_MS));
      await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    } finally {
      vi.useRealTimers();
    }
  });

  it("backs off once nothing is left running", async () => {
    vi.useFakeTimers();

    try {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>;
      mockAlways(listResponse([job({ status: "completed", is_terminal: true })], 0));

      renderList();
      await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

      // The fast interval passes and nothing happens.
      await act(() => vi.advanceTimersByTimeAsync(ACTIVE_POLL_MS));
      expect(fetchMock).toHaveBeenCalledTimes(1);

      // The idle interval does trigger a refresh.
      await act(() => vi.advanceTimersByTimeAsync(IDLE_POLL_MS));
      await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    } finally {
      vi.useRealTimers();
    }
  });

  it("uses the intervals the UI documents", () => {
    expect(ACTIVE_POLL_MS).toBe(3_000);
    expect(IDLE_POLL_MS).toBe(15_000);
  });
});

describe("retrying", () => {
  it("offers a retry button only on failed jobs", async () => {
    mockOnce(
      listResponse([
        job({ id: 1, status: "failed", is_terminal: true, is_retryable: true }),
        job({ id: 2, status: "completed", is_terminal: true, is_retryable: false }),
      ]),
    );

    renderList();

    expect(await screen.findAllByRole("button", { name: "Retry" })).toHaveLength(1);
  });

  it("posts to the retry route", async () => {
    mockOnce(listResponse([job({ status: "failed", is_terminal: true, is_retryable: true })]));

    renderList();

    const button = await screen.findByRole("button", { name: "Retry" });
    mockOnce({ message: "queued" }, true, 202);
    await userEvent.click(button);

    await vi.waitFor(() =>
      expect(globalThis.fetch).toHaveBeenCalledWith(
        "/api/jobs/1/retry",
        expect.objectContaining({ method: "POST" }),
      ),
    );
  });
});

describe("errors", () => {
  it("shows the error and a retry button", async () => {
    mockOnce({ message: "Is the Laravel server running?" }, false, 503);

    renderList();

    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(screen.getByText("Is the Laravel server running?")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("treats a malformed payload as an error", async () => {
    mockOnce({ data: [{ id: "not-a-number" }], meta: {} });

    renderList();

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });
});
