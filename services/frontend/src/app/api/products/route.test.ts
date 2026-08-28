import { NextRequest } from "next/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * Tests for the BFF route.
 *
 * The single most important assertion here is that the API token never appears
 * in a response body. Everything else is about forwarding only what we
 * understand and failing in a way the UI can present.
 */

const fetchProducts = vi.fn();

vi.mock("@/lib/api/client", async () => {
  const actual = await vi.importActual<typeof import("@/lib/api/client")>(
    "@/lib/api/client",
  );

  return { ...actual, fetchProducts };
});

const { GET } = await import("./route");
const { ApiError } = await import("@/lib/api/client");

const listResponse = {
  data: [],
  meta: { current_page: 1, last_page: 0, per_page: 24, total: 0 },
};

/** Build a request for the route under test. */
function request(query = ""): NextRequest {
  return new NextRequest(`http://localhost:3000/api/products${query}`);
}

beforeEach(() => {
  fetchProducts.mockReset();
  fetchProducts.mockResolvedValue(listResponse);
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("GET /api/products", () => {
  it("returns the product list", async () => {
    const response = await GET(request());

    expect(response.status).toBe(200);
    await expect(response.json()).resolves.toEqual(listResponse);
  });

  it("uses sensible defaults when no query is given", async () => {
    await GET(request());

    expect(fetchProducts).toHaveBeenCalledWith({
      page: 1,
      perPage: 24,
      source: undefined,
    });
  });

  it("forwards valid pagination parameters", async () => {
    await GET(request("?page=3&per_page=12"));

    expect(fetchProducts).toHaveBeenCalledWith(
      expect.objectContaining({ page: 3, perPage: 12 }),
    );
  });

  it("forwards a known source filter", async () => {
    await GET(request("?source=jumia"));

    expect(fetchProducts).toHaveBeenCalledWith(
      expect.objectContaining({ source: "jumia" }),
    );
  });

  // Passing the query string through verbatim would let a client probe the
  // upstream API with arbitrary input.
  it("ignores an unknown source rather than forwarding it", async () => {
    await GET(request("?source=ebay"));

    expect(fetchProducts).toHaveBeenCalledWith(
      expect.objectContaining({ source: undefined }),
    );
  });

  it("falls back to defaults for nonsensical pagination", async () => {
    await GET(request("?page=-5&per_page=abc"));

    expect(fetchProducts).toHaveBeenCalledWith(
      expect.objectContaining({ page: 1, perPage: 24 }),
    );
  });

  it("caps the page size so a client cannot request the whole table", async () => {
    await GET(request("?per_page=100000"));

    expect(fetchProducts).toHaveBeenCalledWith(
      expect.objectContaining({ perPage: 100 }),
    );
  });

  it("does not let the browser cache the response", async () => {
    const response = await GET(request());

    expect(response.headers.get("Cache-Control")).toBe("no-store");
  });
});

describe("error handling", () => {
  it("passes an ApiError's status and message through", async () => {
    fetchProducts.mockRejectedValue(
      new ApiError("Could not reach the API. Is the Laravel server running?", 503),
    );

    const response = await GET(request());

    expect(response.status).toBe(503);
    await expect(response.json()).resolves.toEqual({
      message: "Could not reach the API. Is the Laravel server running?",
    });
  });

  it("reports an authentication failure as 401", async () => {
    fetchProducts.mockRejectedValue(new ApiError("The API rejected our token.", 401));

    expect((await GET(request())).status).toBe(401);
  });

  // An unexpected error could carry anything in its message, so it is logged
  // server-side and reported generically.
  it("does not leak details of an unexpected error", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    fetchProducts.mockRejectedValue(
      new Error("Connection string: mysql://root:hunter2@127.0.0.1/plam_task"),
    );

    const response = await GET(request());
    const body = await response.json();

    expect(response.status).toBe(500);
    expect(body.message).toBe(
      "An unexpected error occurred while loading products.",
    );
    expect(JSON.stringify(body)).not.toContain("hunter2");
  });
});

describe("token confidentiality", () => {
  // The reason this route exists at all.
  it("never includes the API token in a response", async () => {
    vi.stubEnv("BACKEND_API_TOKEN", "1|supersecrettokenvalue");

    const body = await (await GET(request())).text();

    expect(body).not.toContain("supersecrettokenvalue");
    expect(body.toLowerCase()).not.toContain("authorization");

    vi.unstubAllEnvs();
  });

  it("does not leak the token when the upstream call fails", async () => {
    vi.stubEnv("BACKEND_API_TOKEN", "1|supersecrettokenvalue");
    fetchProducts.mockRejectedValue(new ApiError("Upstream failed.", 502));

    const body = await (await GET(request())).text();

    expect(body).not.toContain("supersecrettokenvalue");

    vi.unstubAllEnvs();
  });
});
