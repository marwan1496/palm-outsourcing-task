/**
 * Stub for the `server-only` package under Vitest.
 *
 * In a real Next.js build, importing "server-only" makes the build FAIL if the
 * module ever ends up in a client bundle - that is the guard that keeps the API
 * token off the browser.
 *
 * Outside Next.js the package's default entry throws on import, which would
 * break these tests. Vitest aliases it to this empty module instead, so the
 * server-side code can be unit tested while the real guard still applies to
 * every actual build.
 */
export {};
