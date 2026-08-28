import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { Product } from "@/lib/api/schemas";

import { ProductGrid, REFRESH_INTERVAL_MS } from "./ProductGrid";

function product(overrides: Partial<Product> = {}): Product {
  return {
    id: 1,
    title: "Test Product",
    price: 129900,
    price_formatted: "EGP 1,299.00",
    currency: "EGP",
    image_url: "https://eg.jumia.is/a.jpg",
    source: "jumia",
    source_label: "Jumia",
    source_url: "https://www.jumia.com.eg/a.html",
    scraped_at: "2026-08-29T12:00:00+00:00",
    created_at: "2026-08-29T12:00:00+00:00",
    ...overrides,
  };
}

function listResponse(products: Product[]) {
  return {
    data: products,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 24,
      total: products.length,
    },
  };
}

/**
 * Wrap the grid in its own QueryClient, with retries off so a failure test
 * fails immediately rather than retrying for seconds.
 */
function Wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

const renderGrid = () => render(<ProductGrid />, { wrapper: Wrapper });

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn());
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

/** Make the stubbed fetch resolve with a JSON body. */
function mockFetchOnce(body: unknown, ok = true, status = 200) {
  (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
    ok,
    status,
    json: async () => body,
  });
}

describe("ProductGrid", () => {
  it("shows a loading skeleton before data arrives", () => {
    (globalThis.fetch as ReturnType<typeof vi.fn>).mockReturnValue(
      new Promise(() => {}), // never resolves
    );

    renderGrid();

    expect(screen.getByLabelText("Loading products")).toBeInTheDocument();
  });

  it("renders the products once loaded", async () => {
    mockFetchOnce(listResponse([product(), product({ id: 2, title: "Second Product" })]));

    renderGrid();

    expect(await screen.findByText("Test Product")).toBeInTheDocument();
    expect(screen.getByText("Second Product")).toBeInTheDocument();
  });

  it("fetches from the BFF route, never from Laravel directly", async () => {
    mockFetchOnce(listResponse([product()]));

    renderGrid();
    await screen.findByText("Test Product");

    // The browser must not know the backend's address, and must send no token.
    expect(globalThis.fetch).toHaveBeenCalledWith(
      "/api/products",
      expect.objectContaining({ headers: { Accept: "application/json" } }),
    );

    const [, options] = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(JSON.stringify(options)).not.toContain("Authorization");
  });

  it("shows how many products there are", async () => {
    mockFetchOnce(listResponse([product(), product({ id: 2 })]));

    renderGrid();

    expect(await screen.findByText("2")).toBeInTheDocument();
    expect(screen.getByText("products")).toBeInTheDocument();
  });

  it("shows a freshness indicator so the refresh is visible", async () => {
    mockFetchOnce(listResponse([product()]));

    renderGrid();
    await screen.findByText("Test Product");

    expect(screen.getByText(/Updated/)).toBeInTheDocument();
  });

  it("shows a helpful empty state rather than a blank page", async () => {
    mockFetchOnce(listResponse([]));

    renderGrid();

    expect(await screen.findByText("No products yet")).toBeInTheDocument();
    expect(screen.getByText(/products:scrape/)).toBeInTheDocument();
  });

  it("shows the error message from the BFF when the request fails", async () => {
    mockFetchOnce({ message: "Is the Laravel server running?" }, false, 503);

    renderGrid();

    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(screen.getByText(/Is the Laravel server running\?/)).toBeInTheDocument();
  });

  it("offers a retry button when loading fails", async () => {
    mockFetchOnce({ message: "boom" }, false, 500);

    renderGrid();

    expect(await screen.findByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  // A payload that does not match the schema is caught at the boundary and
  // surfaced as an error state, rather than rendering `undefined` in the grid.
  it("treats a malformed payload as an error", async () => {
    mockFetchOnce({ data: [{ id: "not-a-number" }], meta: {} });

    renderGrid();

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });

  it("polls every 30 seconds", async () => {
    vi.useFakeTimers();

    try {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>;
      fetchMock.mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => listResponse([product()]),
      });

      renderGrid();

      await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

      await act(() => vi.advanceTimersByTimeAsync(REFRESH_INTERVAL_MS));
      await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

      await act(() => vi.advanceTimersByTimeAsync(REFRESH_INTERVAL_MS));
      await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
    } finally {
      vi.useRealTimers();
    }
  });

  it("uses a 30 second interval, as the brief requires", () => {
    expect(REFRESH_INTERVAL_MS).toBe(30_000);
  });

  // Without keepPreviousData the grid would unmount and flash the skeleton on
  // every poll - a visible blink twice a minute.
  it("keeps showing products while a refresh is in flight", async () => {
    vi.useFakeTimers();

    try {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>;
      fetchMock.mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => listResponse([product({ title: "Persistent Product" })]),
      });

      renderGrid();
      await vi.waitFor(() =>
        expect(screen.getByText("Persistent Product")).toBeInTheDocument(),
      );

      await act(() => vi.advanceTimersByTimeAsync(REFRESH_INTERVAL_MS));

      // Still on screen mid-refresh, and no skeleton.
      expect(screen.getByText("Persistent Product")).toBeInTheDocument();
      expect(screen.queryByLabelText("Loading products")).not.toBeInTheDocument();
    } finally {
      vi.useRealTimers();
    }
  });

  it("picks up newly scraped products on a later poll", async () => {
    vi.useFakeTimers();

    try {
      const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>;

      fetchMock.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => listResponse([product({ title: "First Product" })]),
      });

      renderGrid();
      await vi.waitFor(() =>
        expect(screen.getByText("First Product")).toBeInTheDocument(),
      );

      fetchMock.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () =>
          listResponse([
            product({ title: "First Product" }),
            product({ id: 2, title: "Newly Scraped Product" }),
          ]),
      });

      await act(() => vi.advanceTimersByTimeAsync(REFRESH_INTERVAL_MS));

      await vi.waitFor(() =>
        expect(screen.getByText("Newly Scraped Product")).toBeInTheDocument(),
      );
    } finally {
      vi.useRealTimers();
    }
  });
});
