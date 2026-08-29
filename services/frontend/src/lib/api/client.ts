import "server-only";

import { z } from "zod";

import {
  productListSchema,
  scrapeJobListSchema,
  scrapeSubmissionSchema,
  type ProductList,
  type ScrapeJobList,
  type ScrapeSubmission,
} from "./schemas";

/**
 * Server-side client for the Laravel API.
 *
 * The `server-only` import at the top is a guard rail, not decoration: if any
 * client component ever imports this file, the BUILD FAILS. That makes it
 * impossible to leak the API token into a browser bundle by accident, which is
 * exactly the kind of mistake that is otherwise easy to make and invisible
 * once made.
 */

/** Thrown when the API cannot be reached or answers with an error. */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    /**
     * The raw error body, when the API sent something structured worth
     * passing on. A 422 from the scrape endpoint lists which URLs were
     * rejected and why, and flattening that to a single string would throw
     * away the only detail the user actually needs.
     */
    readonly details?: unknown,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

/**
 * Read configuration from the environment.
 *
 * Note that neither variable is prefixed NEXT_PUBLIC_. That prefix is what
 * tells Next.js to inline a value into the client bundle, so its absence is
 * what keeps the token server-side.
 */
function config() {
  const baseUrl = process.env.BACKEND_API_URL ?? "http://127.0.0.1:8000";
  const token = process.env.BACKEND_API_TOKEN;

  if (!token) {
    throw new ApiError(
      "BACKEND_API_TOKEN is not set. Run `php artisan api:token` in services/backend and add it to .env.local.",
      500,
    );
  }

  return { baseUrl: baseUrl.replace(/\/$/, ""), token };
}

/** Options for fetching the product list. */
export interface FetchProductsOptions {
  page?: number;
  perPage?: number;
  source?: string;
}

/**
 * Fetch products from the Laravel API and validate the response.
 *
 * @throws ApiError when the request fails or the payload is not the expected shape.
 */
export async function fetchProducts(
  options: FetchProductsOptions = {},
): Promise<ProductList> {
  const query = new URLSearchParams();
  if (options.page) query.set("page", String(options.page));
  if (options.perPage) query.set("per_page", String(options.perPage));
  if (options.source) query.set("source", options.source);

  return request(`/api/v1/products${query.size ? `?${query}` : ""}`, productListSchema);
}

/** Options for listing scrape jobs. */
export interface FetchJobsOptions {
  page?: number;
  perPage?: number;
  status?: string;
  batchId?: string;
}

/**
 * List scrape jobs, newest first.
 */
export async function fetchScrapeJobs(
  options: FetchJobsOptions = {},
): Promise<ScrapeJobList> {
  const query = new URLSearchParams();
  if (options.page) query.set("page", String(options.page));
  if (options.perPage) query.set("per_page", String(options.perPage));
  if (options.status) query.set("status", options.status);
  if (options.batchId) query.set("batch_id", options.batchId);

  return request(
    `/api/v1/scrape-jobs${query.size ? `?${query}` : ""}`,
    scrapeJobListSchema,
  );
}

/**
 * Submit URLs for scraping.
 *
 * The API allows a batch to partly succeed, so the result carries both the
 * jobs it queued and the URLs it turned away.
 */
export async function submitScrape(urls: string[]): Promise<ScrapeSubmission> {
  return request("/api/v1/scrape", scrapeSubmissionSchema, {
    method: "POST",
    body: JSON.stringify({ urls }),
  });
}

/**
 * Re-queue a failed job.
 */
export async function retryScrapeJob(id: number): Promise<void> {
  await request(`/api/v1/scrape-jobs/${id}/retry`, z.unknown(), { method: "POST" });
}

/**
 * One place where the token is attached, the response checked and the payload
 * validated.
 *
 * Every call goes through here rather than repeating the fetch/validate dance,
 * which also means there is exactly one line in the codebase that sends the
 * Authorization header.
 */
async function request<T>(
  path: string,
  schema: z.ZodType<T>,
  init: RequestInit = {},
): Promise<T> {
  const { baseUrl, token } = config();

  let response: Response;

  try {
    response = await fetch(`${baseUrl}${path}`, {
      ...init,
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
        ...(init.body ? { "Content-Type": "application/json" } : {}),
        ...init.headers,
      },
      // The client polls, and the backend does its own caching, so Next.js
      // must not add a third layer serving data the user cannot refresh.
      cache: "no-store",
      signal: AbortSignal.timeout(15_000),
    });
  } catch {
    // Almost always "the Laravel server is not running". Worth saying so
    // plainly rather than surfacing a raw TypeError.
    throw new ApiError(
      `Could not reach the API at ${baseUrl}. Is the Laravel server running?`,
      503,
    );
  }

  if (!response.ok) {
    // A 422 carries useful per-URL detail, so pass its body through rather
    // than flattening it to a generic message.
    if (response.status === 422) {
      const body = await response.json().catch(() => null);
      throw new ApiError(
        body?.message ?? "The API rejected the request.",
        422,
        body,
      );
    }

    throw new ApiError(
      response.status === 401
        ? "The API rejected our token. Re-issue it with `php artisan api:token`."
        : `The API responded with ${response.status}.`,
      response.status,
    );
  }

  const body: unknown = await response.json().catch(() => null);

  // Validate at the boundary. A shape change is caught here, once, with a
  // clear message, not later inside a component.
  const parsed = schema.safeParse(body);

  if (!parsed.success) {
    throw new ApiError(
      `The API returned an unexpected shape: ${parsed.error.issues
        .map((issue) => `${issue.path.join(".")} ${issue.message}`)
        .join("; ")}`,
      502,
    );
  }

  return parsed.data;
}
