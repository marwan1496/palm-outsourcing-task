import path from "node:path";

import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";

export default defineConfig({
  plugins: [react()],
  test: {
    // jsdom gives the component tests a DOM to render into.
    environment: "jsdom",

    // Registers jest-dom matchers such as toBeInTheDocument().
    setupFiles: ["./vitest.setup.tsx"],

    globals: true,

    include: ["src/**/*.test.{ts,tsx}"],

    coverage: {
      provider: "v8",
      include: ["src/lib/**", "src/components/**", "src/app/api/**"],
    },
  },
  resolve: {
    // Mirrors the "@/*" path alias from tsconfig.json, which Vitest does not
    // read on its own.
    alias: {
      // import.meta.dirname rather than __dirname: this file is ESM (.mts),
      // where the CommonJS globals do not exist.
      "@": path.resolve(import.meta.dirname, "./src"),

      // The real "server-only" package throws on import outside a Next.js
      // server bundle, which would break these tests. The stub is empty; the
      // genuine build-time guard still applies to every `next build`.
      "server-only": path.resolve(
        import.meta.dirname,
        "./test/server-only-stub.ts",
      ),
    },
  },
});
