import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  images: {
    /**
     * next/image refuses to optimise a remote host that is not listed here.
     *
     * That refusal is a security feature, not an inconvenience: image_url
     * comes from scraped pages, so without an allowlist a compromised or
     * hostile storefront could point our optimiser at any URL on the internet
     * and use this server to fetch it.
     *
     * Each entry pins the protocol and hostname; add a storefront's CDN here
     * when adding its parser.
     */
    remotePatterns: [
      // Jumia's image CDN, per country.
      { protocol: "https", hostname: "*.jumia.is" },
      { protocol: "https", hostname: "*.jumia.com.eg" },

      // Amazon's media CDN.
      { protocol: "https", hostname: "m.media-amazon.com" },
      { protocol: "https", hostname: "images-na.ssl-images-amazon.com" },

      // Placeholder images used by the database factory when seeding demo data.
      { protocol: "https", hostname: "picsum.photos" },
      { protocol: "https", hostname: "fastly.picsum.photos" },
    ],
  },

  // Do not advertise the framework to every visitor.
  poweredByHeader: false,

  /**
   * Baseline security headers for the whole app.
   *
   * The backend sets its own headers on API responses; these cover the pages
   * the browser actually renders.
   */
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "X-Frame-Options", value: "DENY" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          {
            key: "Permissions-Policy",
            value: "camera=(), microphone=(), geolocation=()",
          },
        ],
      },
    ];
  },
};

export default nextConfig;
