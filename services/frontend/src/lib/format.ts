/**
 * Small display helpers.
 *
 * Kept as pure functions with no React and no imports so they are trivial to
 * unit test and cannot pull the framework into a test that does not need it.
 */

/**
 * Format a price given in minor units.
 *
 * The backend already sends `price_formatted`, and components should prefer
 * that. This exists for the cases where the frontend does its own arithmetic -
 * a total, a discount - and still needs to render the result consistently.
 *
 * @param minorUnits Price in the currency's smallest unit (1999 = 19.99).
 * @param currency   ISO 4217 code, e.g. "EGP".
 */
export function formatPrice(minorUnits: number, currency: string): string {
  const major = minorUnits / 100;

  try {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency,
      minimumFractionDigits: 2,
    }).format(major);
  } catch {
    // Intl throws on an unrecognised currency code. Falling back to a plain
    // "EGP 19.99" is far better than crashing a product card over it.
    return `${currency} ${major.toFixed(2)}`;
  }
}

/**
 * Describe how long ago a timestamp was, in words.
 *
 * Used for the "updated Ns ago" indicator, which is what makes the 30-second
 * refresh visible rather than something you have to take on trust.
 *
 * @param date The moment to describe.
 * @param now  Injectable so tests need no fake timers.
 */
export function formatRelativeTime(date: Date, now: Date = new Date()): string {
  const seconds = Math.floor((now.getTime() - date.getTime()) / 1000);

  // Clock skew, or a timestamp from the near future.
  if (seconds < 0) return "just now";
  if (seconds < 5) return "just now";
  if (seconds < 60) return `${seconds}s ago`;

  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;

  const days = Math.floor(hours / 24);
  return `${days}d ago`;
}

/**
 * Shorten a string, ending on a whole word where possible.
 *
 * Product titles are long and vary wildly in length; cutting mid-word looks
 * broken in a grid.
 */
export function truncate(value: string, maxLength: number): string {
  if (value.length <= maxLength) return value;

  const clipped = value.slice(0, maxLength);
  const lastSpace = clipped.lastIndexOf(" ");

  // Only honour the word boundary when there IS one (lastIndexOf returns -1
  // otherwise) and it is reasonably near the end. The `lastSpace > 0` check
  // matters: without it, -1 would be passed to slice(), which counts from the
  // end and would silently drop the final character instead.
  const hasUsableBoundary = lastSpace > 0 && lastSpace > maxLength - 15;
  const cut = hasUsableBoundary ? clipped.slice(0, lastSpace) : clipped;

  return `${cut.trimEnd()}…`;
}
