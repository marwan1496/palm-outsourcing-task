import { NextResponse, type NextRequest } from "next/server";

import { ApiError, fetchScrapeJobs, submitScrape } from "@/lib/api/client";

/**
 * BFF route for scrape jobs: list them, and submit new URLs.
 *
 * Same reasoning as /api/products. The Laravel API needs a Sanctum token, and
 * there is nowhere safe to keep a token in client-side JavaScript, so the
 * browser talks to this route instead and the token stays on the server.
 */

/**
 * GET /api/jobs — list jobs, newest first.
 */
export async function GET(request: NextRequest) {
  const params = request.nextUrl.searchParams;

  // Only forward parameters we understand. Passing the query string through
  // verbatim would let a client probe the upstream API with arbitrary input.
  const status = params.get("status");
  const perPage = Number(params.get("per_page") ?? 30);

  try {
    const jobs = await fetchScrapeJobs({
      perPage: Number.isFinite(perPage) && perPage > 0 ? Math.min(perPage, 100) : 30,
      status: isKnownStatus(status) ? status : undefined,
    });

    return NextResponse.json(jobs, {
      headers: { "Cache-Control": "no-store" },
    });
  } catch (error) {
    return errorResponse(error, "load jobs");
  }
}

/**
 * POST /api/jobs — submit URLs for scraping.
 *
 * Accepts `{ urls: string[] }`. A batch may partly succeed, so the 202 from
 * Laravel carries both the queued jobs and the rejected URLs, and both are
 * passed straight back to the page.
 */
export async function POST(request: NextRequest) {
  let body: unknown;

  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ message: "Expected a JSON body." }, { status: 400 });
  }

  const urls = extractUrls(body);

  if (urls.length === 0) {
    return NextResponse.json(
      { message: "Enter at least one URL." },
      { status: 422 },
    );
  }

  try {
    const result = await submitScrape(urls);

    return NextResponse.json(result, {
      status: 202,
      headers: { "Cache-Control": "no-store" },
    });
  } catch (error) {
    // A 422 means every URL was rejected, and its body says which and why.
    // That detail is the whole point, so it is forwarded rather than
    // flattened into a generic message.
    if (error instanceof ApiError && error.status === 422 && error.details) {
      return NextResponse.json(error.details, { status: 422 });
    }

    return errorResponse(error, "submit URLs");
  }
}

/**
 * Pull a list of URL strings out of an untrusted request body.
 */
function extractUrls(body: unknown): string[] {
  if (typeof body !== "object" || body === null || !("urls" in body)) {
    return [];
  }

  const { urls } = body as { urls: unknown };

  if (!Array.isArray(urls)) {
    return [];
  }

  return urls
    .filter((url): url is string => typeof url === "string")
    .map((url) => url.trim())
    .filter(Boolean)
    // Matches the backend's own cap, so an over-long list is rejected here
    // rather than after a round trip.
    .slice(0, 10);
}

/**
 * Turn an error into a response without leaking anything internal.
 */
function errorResponse(error: unknown, action: string) {
  if (error instanceof ApiError) {
    // ApiError messages are written to be shown, and never contain the token.
    return NextResponse.json({ message: error.message }, { status: error.status });
  }

  console.error(`Unexpected error trying to ${action}`, error);

  return NextResponse.json(
    { message: `An unexpected error occurred while trying to ${action}.` },
    { status: 500 },
  );
}

function isKnownStatus(value: string | null): value is string {
  return (
    value !== null && ["pending", "running", "completed", "failed"].includes(value)
  );
}
