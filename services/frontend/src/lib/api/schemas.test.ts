import { describe, expect, it } from "vitest";

import { productListSchema, productSchema } from "./schemas";

/**
 * A payload matching exactly what ProductResource returns.
 */
function validProduct(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    title: "Samsung Galaxy A55 5G",
    price: 1849900,
    price_formatted: "EGP 18,499.00",
    currency: "EGP",
    image_url: "https://eg.jumia.is/product.jpg",
    source: "jumia",
    source_label: "Jumia",
    source_url: "https://www.jumia.com.eg/product.html",
    scraped_at: "2026-08-29T12:00:00+00:00",
    created_at: "2026-08-29T12:00:00+00:00",
    ...overrides,
  };
}

describe("productSchema", () => {
  it("accepts a well-formed product", () => {
    expect(productSchema.safeParse(validProduct()).success).toBe(true);
  });

  it("accepts a product with no image", () => {
    expect(productSchema.safeParse(validProduct({ image_url: null })).success).toBe(true);
  });

  it("accepts a product that has never been scraped", () => {
    expect(productSchema.safeParse(validProduct({ scraped_at: null })).success).toBe(true);
  });

  it("accepts both supported storefronts", () => {
    expect(productSchema.safeParse(validProduct({ source: "amazon" })).success).toBe(true);
    expect(productSchema.safeParse(validProduct({ source: "jumia" })).success).toBe(true);
  });

  // Each of these would render as `undefined` or crash a component if it were
  // allowed through. Catching them at the boundary is the entire point.
  it("rejects malformed payloads", ({ task }) => {
    const cases: Array<[string, Record<string, unknown>]> = [
      ["missing title", { title: undefined }],
      ["empty title", { title: "" }],
      ["price as a string", { price: "1849900" }],
      ["fractional price", { price: 100.5 }],
      ["negative price", { price: -100 }],
      ["unknown source", { source: "ebay" }],
      ["currency too short", { currency: "EG" }],
      ["image_url not a url", { image_url: "not-a-url" }],
      ["source_url not a url", { source_url: "not-a-url" }],
      ["created_at not a date", { created_at: "yesterday" }],
      ["id as a string", { id: "1" }],
    ];

    for (const [label, override] of cases) {
      const result = productSchema.safeParse(validProduct(override));
      expect(result.success, `expected "${label}" to be rejected`).toBe(false);
    }
  });

  it("rejects a completely different shape", () => {
    expect(productSchema.safeParse({ message: "Unauthenticated." }).success).toBe(false);
  });

  it("rejects null and undefined", () => {
    expect(productSchema.safeParse(null).success).toBe(false);
    expect(productSchema.safeParse(undefined).success).toBe(false);
  });
});

describe("productListSchema", () => {
  it("accepts a full list response", () => {
    const result = productListSchema.safeParse({
      data: [validProduct(), validProduct({ id: 2 })],
      meta: { current_page: 1, last_page: 1, per_page: 24, total: 2 },
    });

    expect(result.success).toBe(true);
    if (result.success) expect(result.data.data).toHaveLength(2);
  });

  it("accepts an empty list", () => {
    const result = productListSchema.safeParse({
      data: [],
      meta: { current_page: 1, last_page: 0, per_page: 24, total: 0 },
    });

    expect(result.success).toBe(true);
  });

  it("rejects a response with no meta", () => {
    expect(productListSchema.safeParse({ data: [] }).success).toBe(false);
  });

  it("rejects a response where one product is malformed", () => {
    const result = productListSchema.safeParse({
      data: [validProduct(), validProduct({ price: "free" })],
      meta: { current_page: 1, last_page: 1, per_page: 24, total: 2 },
    });

    expect(result.success).toBe(false);
  });

  it("rejects an error body returned in place of the list", () => {
    // What the BFF would return if the token were rejected.
    expect(productListSchema.safeParse({ message: "Unauthenticated." }).success).toBe(false);
  });
});
