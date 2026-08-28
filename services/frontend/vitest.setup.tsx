import "@testing-library/jest-dom/vitest";

import { cleanup } from "@testing-library/react";
import { afterEach, vi } from "vitest";

// Unmount anything rendered after each test, so one test's DOM cannot be found
// by the next one.
afterEach(() => {
  cleanup();
});

/**
 * Stub next/image with a plain <img>.
 *
 * The real component needs the Next.js image optimiser, which is not running
 * in a unit test. The stub keeps the same props contract so the tests still
 * assert on src and alt.
 */
vi.mock("next/image", () => ({
  default: ({
    src,
    alt,
    priority,
    fill,
    sizes,
    ...props
  }: {
    src: string;
    alt: string;
    priority?: boolean;
    fill?: boolean;
    sizes?: string;
    [key: string]: unknown;
  }) => {
    // Mirror what next/image actually renders for `priority`, rather than
    // passing the prop straight through: React drops unknown boolean props on
    // a plain <img>, and asserting on loading/fetchpriority tests the
    // behaviour a browser sees rather than an internal prop name.
    return (
      // eslint-disable-next-line @next/next/no-img-element, jsx-a11y/alt-text
      <img
        src={src}
        alt={alt}
        sizes={sizes}
        loading={priority ? "eager" : "lazy"}
        fetchPriority={priority ? "high" : "auto"}
        {...props}
      />
    );
  },
}));
