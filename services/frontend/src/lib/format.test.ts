import { describe, expect, it } from "vitest";

import { formatPrice, formatRelativeTime, truncate } from "./format";

describe("formatPrice", () => {
  it("converts minor units into a currency string", () => {
    expect(formatPrice(129900, "EGP")).toContain("1,299.00");
  });

  it("always shows two decimal places", () => {
    expect(formatPrice(1000, "USD")).toContain("10.00");
  });

  it("handles zero", () => {
    expect(formatPrice(0, "USD")).toContain("0.00");
  });

  it("handles amounts below one major unit", () => {
    expect(formatPrice(50, "USD")).toContain("0.50");
  });

  it("handles very large amounts with grouping", () => {
    expect(formatPrice(123456789, "USD")).toContain("1,234,567.89");
  });

  // Intl.NumberFormat throws on an unknown currency code; a product card must
  // not crash because a scraped page reported something unexpected.
  it("falls back to a plain string for an unrecognised currency", () => {
    expect(formatPrice(1999, "XYZ123")).toBe("XYZ123 19.99");
  });
});

describe("formatRelativeTime", () => {
  const now = new Date("2026-08-29T12:00:00Z");

  it("describes the last few seconds as 'just now'", () => {
    expect(formatRelativeTime(new Date("2026-08-29T11:59:58Z"), now)).toBe("just now");
  });

  it("counts seconds", () => {
    expect(formatRelativeTime(new Date("2026-08-29T11:59:30Z"), now)).toBe("30s ago");
  });

  it("counts minutes", () => {
    expect(formatRelativeTime(new Date("2026-08-29T11:45:00Z"), now)).toBe("15m ago");
  });

  it("counts hours", () => {
    expect(formatRelativeTime(new Date("2026-08-29T09:00:00Z"), now)).toBe("3h ago");
  });

  it("counts days", () => {
    expect(formatRelativeTime(new Date("2026-08-27T12:00:00Z"), now)).toBe("2d ago");
  });

  it("rolls over from seconds to minutes at 60", () => {
    expect(formatRelativeTime(new Date("2026-08-29T11:59:00Z"), now)).toBe("1m ago");
  });

  // Server and browser clocks are never perfectly in sync, so a timestamp can
  // appear to be slightly in the future. "-3s ago" would look broken.
  it("handles a future timestamp from clock skew", () => {
    expect(formatRelativeTime(new Date("2026-08-29T12:00:03Z"), now)).toBe("just now");
  });
});

describe("truncate", () => {
  it("leaves a short string untouched", () => {
    expect(truncate("Short title", 50)).toBe("Short title");
  });

  it("leaves a string of exactly the limit untouched", () => {
    expect(truncate("12345", 5)).toBe("12345");
  });

  it("shortens a long string and appends an ellipsis", () => {
    const result = truncate("a".repeat(100), 20);

    expect(result).toHaveLength(21); // 20 characters plus the ellipsis
    expect(result.endsWith("…")).toBe(true);
  });

  it("cuts on a word boundary when one is near the end", () => {
    expect(truncate("Samsung Galaxy A55 5G Dual SIM 256GB", 25)).toBe(
      "Samsung Galaxy A55 5G…",
    );
  });

  // A single very long word has no usable boundary; honouring one would
  // shorten the string far more than asked.
  it("cuts mid-word when there is no nearby boundary", () => {
    expect(truncate("Supercalifragilisticexpialidocious", 10)).toBe("Supercalif…");
  });
});
