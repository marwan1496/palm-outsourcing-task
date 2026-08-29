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

/* -------------------------------------------------------------------------
 * Scrape jobs
 * ---------------------------------------------------------------------- */

/** Mirrors App\Enums\ScrapeJobStatus. */
export const scrapeJobStatusSchema = z.enum([
  "pending",
  "running",
  "completed",
  "failed",
]);

/**
 * One submitted URL and how it turned out.
 *
 * Matches App\Http\Resources\V1\ScrapeJobResource.
 */
export const scrapeJobSchema = z.object({
  id: z.number().int().positive(),
  batch_id: z.string(),
  url: z.string(),

  status: scrapeJobStatusSchema,
  status_label: z.string(),
  is_terminal: z.boolean(),
  is_retryable: z.boolean(),

  /** Why it failed, shown as-is in the UI. Null while it is still going. */
  error: z.string().nullable(),
  attempts: z.number().int().nonnegative(),
  duration_ms: z.number().int().nonnegative().nullable(),

  /** Only present once the job produced something. */
  product: productSchema.nullable().optional(),

  started_at: z.iso.datetime({ offset: true }).nullable(),
  finished_at: z.iso.datetime({ offset: true }).nullable(),
  created_at: z.iso.datetime({ offset: true }),
});

export const scrapeJobListSchema = z.object({
  data: z.array(scrapeJobSchema),
  meta: paginationMetaSchema.extend({
    /**
     * How many jobs across ALL pages are still pending or running.
     *
     * This drives the polling speed. Counting the current page instead would
     * be wrong the moment there is more than one page.
     */
    unfinished: z.number().int().nonnegative(),
  }),
});

/** A URL the API refused, and the reason it gave. */
export const rejectedUrlSchema = z.object({
  url: z.string(),
  reason: z.string(),
});

/** The 202 from POST /scrape. A batch may partly succeed. */
export const scrapeSubmissionSchema = z.object({
  message: z.string(),
  batch_id: z.string(),
  accepted: z.array(scrapeJobSchema),
  rejected: z.array(rejectedUrlSchema),
});

export type ScrapeJob = z.infer<typeof scrapeJobSchema>;
export type ScrapeJobStatus = z.infer<typeof scrapeJobStatusSchema>;
export type ScrapeJobList = z.infer<typeof scrapeJobListSchema>;
export type RejectedUrl = z.infer<typeof rejectedUrlSchema>;
export type ScrapeSubmission = z.infer<typeof scrapeSubmissionSchema>;
