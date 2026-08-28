import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Product } from "@/lib/api/schemas";

import { ProductCard } from "./ProductCard";

function product(overrides: Partial<Product> = {}): Product {
  return {
    id: 1,
    title: "Samsung Galaxy A55 5G Dual SIM 256GB",
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

describe("ProductCard", () => {
  it("renders the product title", () => {
    render(<ProductCard product={product()} />);

    expect(screen.getByRole("heading")).toHaveTextContent("Samsung Galaxy A55");
  });

  // The backend already localised this string, so the card must show it
  // verbatim rather than reformatting and risking a different result.
  it("renders the price exactly as the backend formatted it", () => {
    render(<ProductCard product={product()} />);

    expect(screen.getByText("EGP 18,499.00")).toBeInTheDocument();
  });

  it("renders the product image with the title as alt text", () => {
    render(<ProductCard product={product()} />);

    const image = screen.getByRole("img");
    expect(image).toHaveAttribute("src", "https://eg.jumia.is/product.jpg");
    expect(image).toHaveAttribute("alt", product().title);
  });

  it("shows a placeholder instead of a broken image when there is none", () => {
    render(<ProductCard product={product({ image_url: null })} />);

    expect(screen.queryByRole("img")).not.toBeInTheDocument();
    expect(screen.getByText("No image available")).toBeInTheDocument();
  });

  it("labels which storefront the product came from", () => {
    render(<ProductCard product={product({ source_label: "Amazon" })} />);

    expect(screen.getByText("Amazon")).toBeInTheDocument();
  });

  it("links to the original product page", () => {
    render(<ProductCard product={product()} />);

    expect(screen.getByRole("link", { name: "View" })).toHaveAttribute(
      "href",
      "https://www.jumia.com.eg/product.html",
    );
  });

  // Without noopener the destination page can reach back through
  // window.opener and navigate this tab somewhere else.
  it("opens the external link safely", () => {
    render(<ProductCard product={product()} />);

    const link = screen.getByRole("link", { name: "View" });
    expect(link).toHaveAttribute("target", "_blank");
    expect(link.getAttribute("rel")).toContain("noopener");
    expect(link.getAttribute("rel")).toContain("noreferrer");
  });

  it("truncates a very long title but keeps the full text available on hover", () => {
    const longTitle = "A ".repeat(100).trim();

    render(<ProductCard product={product({ title: longTitle })} />);

    const heading = screen.getByRole("heading");
    expect(heading.textContent!.length).toBeLessThan(longTitle.length);
    expect(heading).toHaveAttribute("title", longTitle);
  });

  it("renders a title containing HTML as text, not markup", () => {
    // Titles come from scraped pages, so this must never be interpreted.
    render(<ProductCard product={product({ title: "<script>alert(1)</script>" })} />);

    expect(screen.getByRole("heading").textContent).toContain("<script>");
    expect(document.querySelector("script")).toBeNull();
  });
});
