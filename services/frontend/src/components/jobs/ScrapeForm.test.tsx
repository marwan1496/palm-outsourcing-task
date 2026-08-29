import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ScrapeForm, parseUrls } from "./ScrapeForm";

function Wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

const renderForm = () => render(<ScrapeForm />, { wrapper: Wrapper });

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn());
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

function mockResponse(body: unknown, ok = true, status = 202) {
  (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
    ok,
    status,
    json: async () => body,
  });
}

describe("parseUrls", () => {
  it("splits on newlines", () => {
    expect(parseUrls("https://a.test\nhttps://b.test")).toEqual([
      "https://a.test",
      "https://b.test",
    ]);
  });

  it("ignores blank lines and trims whitespace", () => {
    expect(parseUrls("  https://a.test  \n\n\n   \nhttps://b.test")).toEqual([
      "https://a.test",
      "https://b.test",
    ]);
  });

  // Pasting a list means duplicating a line sooner or later.
  it("drops duplicates", () => {
    expect(parseUrls("https://a.test\nhttps://a.test\nhttps://b.test")).toEqual([
      "https://a.test",
      "https://b.test",
    ]);
  });

  it("handles carriage returns from Windows clipboards", () => {
    expect(parseUrls("https://a.test\r\nhttps://b.test")).toHaveLength(2);
  });

  it("returns nothing for an empty box", () => {
    expect(parseUrls("")).toEqual([]);
    expect(parseUrls("   \n  \n ")).toEqual([]);
  });
});

describe("the form", () => {
  it("disables the button until a URL is entered", async () => {
    renderForm();

    expect(screen.getByRole("button", { name: "Scrape" })).toBeDisabled();

    await userEvent.type(screen.getByLabelText("Product URLs"), "https://a.test");

    expect(screen.getByRole("button", { name: "Scrape" })).toBeEnabled();
  });

  it("counts the URLs entered", async () => {
    renderForm();

    await userEvent.type(
      screen.getByLabelText("Product URLs"),
      "https://a.test\nhttps://b.test",
    );

    expect(screen.getByText("2 URLs")).toBeInTheDocument();
  });

  // The backend caps at ten, so catch it here rather than after a round trip.
  it("blocks submission of more than ten URLs", async () => {
    renderForm();

    const urls = Array.from({ length: 11 }, (_, i) => `https://a${i}.test`).join("\n");
    await userEvent.type(screen.getByLabelText("Product URLs"), urls);

    expect(screen.getByText(/10 maximum/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Scrape" })).toBeDisabled();
  });

  it("submits the parsed URLs to the BFF route", async () => {
    mockResponse({ accepted: [{ url: "https://a.test" }], rejected: [] });
    renderForm();

    await userEvent.type(screen.getByLabelText("Product URLs"), "https://a.test");
    await userEvent.click(screen.getByRole("button", { name: "Scrape" }));

    await waitFor(() =>
      expect(globalThis.fetch).toHaveBeenCalledWith(
        "/api/jobs",
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({ urls: ["https://a.test"] }),
        }),
      ),
    );
  });

  it("never sends a credential from the browser", async () => {
    mockResponse({ accepted: [], rejected: [] });
    renderForm();

    await userEvent.type(screen.getByLabelText("Product URLs"), "https://a.test");
    await userEvent.click(screen.getByRole("button", { name: "Scrape" }));

    await waitFor(() => expect(globalThis.fetch).toHaveBeenCalled());

    const [, options] = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(JSON.stringify(options)).not.toContain("Authorization");
  });
});

describe("partial success", () => {
  // The behaviour that matters most: one bad line must not lose the others,
  // and the user has to be told which one was wrong.
  it("reports rejected URLs alongside the accepted ones", async () => {
    mockResponse({
      accepted: [{ url: "https://www.jumia.com.eg/good.html" }],
      rejected: [{ url: "https://ebay.com/x", reason: "No parser is available." }],
    });
    renderForm();

    await userEvent.type(
      screen.getByLabelText("Product URLs"),
      "https://www.jumia.com.eg/good.html\nhttps://ebay.com/x",
    );
    await userEvent.click(screen.getByRole("button", { name: "Scrape" }));

    expect(await screen.findByText(/1 URL was rejected/)).toBeInTheDocument();
    expect(screen.getByText("No parser is available.")).toBeInTheDocument();
  });

  // Clearing the whole box would throw away a URL the user only mistyped.
  it("keeps rejected URLs in the box so they can be corrected", async () => {
    mockResponse({
      accepted: [{ url: "https://www.jumia.com.eg/good.html" }],
      rejected: [{ url: "https://ebay.com/x", reason: "Unsupported." }],
    });
    renderForm();

    const box = screen.getByLabelText("Product URLs");
    await userEvent.type(
      box,
      "https://www.jumia.com.eg/good.html\nhttps://ebay.com/x",
    );
    await userEvent.click(screen.getByRole("button", { name: "Scrape" }));

    await waitFor(() => expect(box).toHaveValue("https://ebay.com/x"));
  });

  it("empties the box when everything was accepted", async () => {
    mockResponse({ accepted: [{ url: "https://a.test" }], rejected: [] });
    renderForm();

    const box = screen.getByLabelText("Product URLs");
    await userEvent.type(box, "https://a.test");
    await userEvent.click(screen.getByRole("button", { name: "Scrape" }));

    await waitFor(() => expect(box).toHaveValue(""));
  });
});

describe("errors", () => {
  it("shows the message when the whole batch is rejected", async () => {
    mockResponse(
      {
        message: "None of the submitted URLs could be scraped.",
        rejected: [{ url: "https://169.254.169.254/", reason: "Non-public address." }],
      },
      false,
      422,
    );
    renderForm();

    await userEvent.type(screen.getByLabelText("Product URLs"), "https://169.254.169.254/");
    await userEvent.click(screen.getByRole("button", { name: "Scrape" }));

    expect(await screen.findByText("Non-public address.")).toBeInTheDocument();
  });

  it("shows a plain error when the request fails outright", async () => {
    mockResponse({ message: "Is the Laravel server running?" }, false, 503);
    renderForm();

    await userEvent.type(screen.getByLabelText("Product URLs"), "https://a.test");
    await userEvent.click(screen.getByRole("button", { name: "Scrape" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Is the Laravel server running?",
    );
  });
});
