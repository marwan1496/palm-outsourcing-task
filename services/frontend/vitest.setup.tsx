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
    ...props
  }: {
    src: string;
    alt: string;
    [key: string]: unknown;
  }) => {
    // eslint-disable-next-line @next/next/no-img-element, jsx-a11y/alt-text
    return <img src={src} alt={alt} {...props} />;
  },
}));
