import { z } from "zod";

/**
 * Runtime validation for everything the Laravel API returns.
 *
 * WHY THIS EXISTS
 *
 * TypeScript types are erased at build time. `const data = await res.json() as
 * Product[]` is a promise the compiler cannot keep - if the API changes a field
 * or returns an error body, the cast succeeds and the app crashes later, deep
 * inside a component, with a confusing message.
 *
 * Zod checks the shape at runtime, at the boundary, once. If the payload is
 * wrong we find out immediately and can show a proper error state instead of
 * rendering `undefined`.
 *
 * The types below are inferred FROM the schemas, so the validation and the
 * types can never drift apart.
 */

/** Storefronts the backend can scrape. Mirrors App\Enums\ProductSource. */
export const productSourceSchema = z.enum(["jumia", "amazon"]);

/**
 * One product, matching App\Http\Resources\V1\ProductResource exactly.
 */
export const productSchema = z.object({
  id: z.number().int().positive(),
  title: z.string().min(1),

  /**
   * Price in minor units (piastres/cents) - an exact integer.
   * `price_formatted` is the display string the backend already localised, so
   * the frontend never has to know how many decimal places a currency has.
   */
  price: z.number().int().nonnegative(),
  price_formatted: z.string(),
  currency: z.string().length(3),

  /** Null when the scraped page had no usable image. */
  image_url: z.url().nullable(),

  source: productSourceSchema,
  source_label: z.string(),
  source_url: z.url(),

  scraped_at: z.iso.datetime({ offset: true }).nullable(),
  created_at: z.iso.datetime({ offset: true }),
});

/** Pagination metadata. */
export const paginationMetaSchema = z.object({
  current_page: z.number().int().positive(),
  last_page: z.number().int().nonnegative(),
  per_page: z.number().int().positive(),
  total: z.number().int().nonnegative(),
});

/** The full response from GET /api/v1/products. */
export const productListSchema = z.object({
  data: z.array(productSchema),
  meta: paginationMetaSchema,
});

export type Product = z.infer<typeof productSchema>;
export type ProductSource = z.infer<typeof productSourceSchema>;
export type PaginationMeta = z.infer<typeof paginationMetaSchema>;
export type ProductList = z.infer<typeof productListSchema>;
