import { NextResponse, type NextRequest } from "next/server";

import { ApiError, fetchProducts } from "@/lib/api/client";

/**
 * Backend-for-Frontend route: the browser's only way to reach product data.
 *
 * THIS ROUTE IS THE SECURITY DESIGN, so it is worth being explicit about why
 * it exists rather than having the browser call Laravel directly.
 *
 * The Laravel API requires a Sanctum token. If the browser called it directly,
 * that token would have to ship in the JavaScript bundle - which means anyone
 * who opens devtools has a permanent credential for the API. There is no way
 * to hide a secret in client-side code; the only fix is not to put it there.
 *
 * So the browser calls THIS route, which runs on the server. It reads the
 * token from a non-public environment variable, calls Laravel, and returns
 * only the product data. The token never crosses the network to the client.
 *
 * Two useful side effects:
 *   - No CORS configuration is needed, because this is a same-origin request
 *     from the browser's point of view.
 *   - Laravel's URL can stay on a private network, unreachable from outside.
 */

export async function GET(request: NextRequest) {
  const params = request.nextUrl.searchParams;

  // Only forward parameters we understand. Passing the query string through
  // verbatim would let a client probe the upstream API with arbitrary input.
  const page = Number(params.get("page") ?? 1);
  const perPage = Number(params.get("per_page") ?? 24);
  const source = params.get("source") ?? undefined;

  try {
    const products = await fetchProducts({
      page: Number.isFinite(page) && page > 0 ? page : 1,
      perPage: Number.isFinite(perPage) && perPage > 0 ? Math.min(perPage, 100) : 24,
      source: source === "jumia" || source === "amazon" ? source : undefined,
    });

    return NextResponse.json(products, {
      // The client polls every 30s and wants fresh data each time; the backend
      // already caches, so there is nothing to gain from caching again here.
      headers: { "Cache-Control": "no-store" },
    });
  } catch (error) {
    if (error instanceof ApiError) {
      // The message is safe to surface: ApiError messages are written for
      // this purpose and never contain the token or upstream internals.
      return NextResponse.json(
        { message: error.message },
        { status: error.status },
      );
    }

    // Anything unexpected is logged server-side and reported generically, so
    // an unhandled error cannot leak a stack trace to the browser.
    console.error("Unexpected error in /api/products", error);

    return NextResponse.json(
      { message: "An unexpected error occurred while loading products." },
      { status: 500 },
    );
  }
}
