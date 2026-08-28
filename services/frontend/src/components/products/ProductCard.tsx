import Image from "next/image";

import type { Product } from "@/lib/api/schemas";
import { truncate } from "@/lib/format";

/**
 * One product in the grid.
 *
 * A server component - it has no state and no event handlers, so there is no
 * reason to ship its JavaScript to the browser.
 *
 * @param priority Set for cards above the fold. Next.js lazy-loads images by
 *                 default, which delays the Largest Contentful Paint for the
 *                 images a visitor can already see. `priority` loads them
 *                 eagerly with a high fetch priority instead. It must NOT be
 *                 set on every card, or the browser contends for bandwidth
 *                 fetching images nobody has scrolled to.
 */
export function ProductCard({
  product,
  priority = false,
}: {
  product: Product;
  priority?: boolean;
}) {
  return (
    <article className="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:border-slate-300 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900 dark:hover:border-slate-700">
      <div className="relative aspect-square overflow-hidden bg-slate-100 dark:bg-slate-800">
        {product.image_url ? (
          <Image
            src={product.image_url}
            alt={product.title}
            fill
            // Tells the browser how wide the image will actually be at each
            // breakpoint, so it downloads a 300px file on mobile rather than
            // a 600px one. Without this, `fill` assumes full viewport width.
            sizes="(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 25vw"
            priority={priority}
            className="object-cover transition duration-300 group-hover:scale-105"
          />
        ) : (
          <PlaceholderImage />
        )}

        <span className="absolute left-2 top-2 rounded-full bg-slate-900/75 px-2 py-0.5 text-xs font-medium text-white backdrop-blur-sm">
          {product.source_label}
        </span>
      </div>

      <div className="flex flex-1 flex-col gap-2 p-4">
        <h2
          className="text-sm font-medium leading-snug text-slate-900 dark:text-slate-100"
          // The full title is available on hover, since the visible one is
          // truncated to keep the grid rows even.
          title={product.title}
        >
          {truncate(product.title, 70)}
        </h2>

        <div className="mt-auto flex items-end justify-between gap-2 pt-2">
          {/* price_formatted comes pre-localised from the backend, so the
              frontend never has to know a currency's decimal rules. */}
          <p className="text-base font-semibold text-slate-900 dark:text-white">
            {product.price_formatted}
          </p>

          <a
            href={product.source_url}
            target="_blank"
            // noreferrer/noopener: without them the destination page can reach
            // back through window.opener and navigate this tab.
            rel="noopener noreferrer"
            className="text-xs font-medium text-blue-600 underline-offset-2 hover:underline dark:text-blue-400"
          >
            View
          </a>
        </div>
      </div>
    </article>
  );
}

/**
 * Shown when a scraped page had no usable image.
 */
function PlaceholderImage() {
  return (
    <div className="flex h-full w-full items-center justify-center text-slate-400 dark:text-slate-600">
      <svg
        className="h-12 w-12"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.5}
        viewBox="0 0 24 24"
        aria-hidden="true"
      >
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 6h.008v.008H18V6Z"
        />
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          d="M2.25 6.75v10.5A2.25 2.25 0 0 0 4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25Z"
        />
      </svg>
      <span className="sr-only">No image available</span>
    </div>
  );
}
