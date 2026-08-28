import "server-only";

import { productListSchema, type ProductList } from "./schemas";

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
  const { baseUrl, token } = config();

  const query = new URLSearchParams();
  if (options.page) query.set("page", String(options.page));
  if (options.perPage) query.set("per_page", String(options.perPage));
  if (options.source) query.set("source", options.source);

  const url = `${baseUrl}/api/v1/products${query.size ? `?${query}` : ""}`;

  let response: Response;

  try {
    response = await fetch(url, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
      },
      // The browser polls every 30 seconds and the backend does its own
      // caching, so Next.js must not add a third cache layer that serves
      // stale data the user cannot refresh.
      cache: "no-store",
      signal: AbortSignal.timeout(10_000),
    });
  } catch (error) {
    // Almost always "the Laravel server is not running" - worth saying so
    // plainly rather than surfacing a raw TypeError.
    throw new ApiError(
      `Could not reach the API at ${baseUrl}. Is the Laravel server running?`,
      503,
    );
  }

  if (!response.ok) {
    throw new ApiError(
      response.status === 401
        ? "The API rejected our token. Re-issue it with `php artisan api:token`."
        : `The API responded with ${response.status}.`,
      response.status,
    );
  }

  const body: unknown = await response.json();

  // Validate at the boundary. A shape change is caught here, once, with a
  // clear message - not later inside a component.
  const parsed = productListSchema.safeParse(body);

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
