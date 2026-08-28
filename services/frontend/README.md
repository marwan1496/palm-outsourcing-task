# Frontend — Next.js 16

The `/products` page: a responsive grid that refreshes itself every 30 seconds.

## Setup

```bash
npm install
cp .env.local.example .env.local
# paste a token from: cd ../backend && php artisan api:token frontend
npm run dev          # :3000
```

Then open **http://localhost:3000/products**.

## The security design

**The browser never holds the API token.** This is the single most important thing about the
frontend.

The Laravel API requires a Sanctum token. If the browser called it directly, the token would have to
ship in the JavaScript bundle — and there is no way to hide a secret in client-side code. So:

```
browser  ──▶  /api/products          ──▶  Laravel /api/v1/products
              (Next.js route handler)      Authorization: Bearer …
              runs on the SERVER
```

Three reinforcing guards:

1. `BACKEND_API_TOKEN` has **no `NEXT_PUBLIC_` prefix**, so Next.js never inlines it into the bundle.
2. `src/lib/api/client.ts` begins with `import "server-only"` — if a client component ever imports
   it, **the build fails**.
3. `src/app/api/products/route.test.ts` asserts the token never appears in a response, including on
   the error path.

Verified against the production build: the token, the env var name, the backend URL, and the string
`Authorization` are all absent from every file in `.next/static/`.

Side benefits: no CORS setup is needed, and Laravel can sit on a private network.

## Structure

```
src/
├── app/
│   ├── layout.tsx                 QueryProvider wraps the tree
│   ├── page.tsx                   redirects to /products
│   ├── products/
│   │   ├── page.tsx               server component shell
│   │   ├── loading.tsx            skeleton matching the real layout
│   │   └── error.tsx              route error boundary
│   └── api/products/route.ts      ← the BFF route
├── components/products/           ProductGrid (client) · ProductCard · Skeleton
├── lib/
│   ├── api/client.ts              server-only fetch, holds the token
│   ├── api/schemas.ts             Zod validation at the boundary
│   └── format.ts                  pure display helpers
└── providers/QueryProvider.tsx
```

Only `ProductGrid` is a client component. The page shell and the product cards render on the server.

## The 30-second refresh

TanStack Query with `refetchInterval: 30_000` and `placeholderData: keepPreviousData`.

`keepPreviousData` matters: without it the grid would unmount and show the skeleton on every poll —
a visible flash of empty boxes twice a minute. Instead the old data stays on screen until the new
data replaces it.

The "Updated Ns ago" indicator exists so the polling is *visible* rather than something you have to
take on trust. It ticks once a second via its own interval, because TanStack Query only re-renders
when data changes.

## Runtime validation

Every API payload is parsed with Zod before use. TypeScript types are erased at build time, so
`as Product[]` is a promise the compiler cannot keep — if the API changes a field, the cast succeeds
and the app crashes later inside a component. Zod catches it at the boundary, once, with a clear
message. The TypeScript types are inferred *from* the schemas, so they cannot drift apart.

## Tests

```bash
npm test              # 65 tests
npm run typecheck
npm run test:coverage
```

Vitest 4 + Testing Library. The poll interval is tested with fake timers, and the BFF route has
dedicated tests for token confidentiality.

## Notes

- `next.config.ts` pins `images.remotePatterns` to the storefront CDNs. That allowlist is a security
  control: `image_url` comes from scraped pages, so without it a hostile page could point the image
  optimiser at any URL on the internet.
- Product titles come from scraped pages and are rendered as text, never as markup — there is a test
  for that.
- This is Next.js 16; the project's `AGENTS.md` notes that its APIs differ from earlier versions.
