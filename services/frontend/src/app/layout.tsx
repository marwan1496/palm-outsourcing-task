import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";

import { QueryProvider } from "@/providers/QueryProvider";

import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Palm Task",
  description:
    "Products scraped from Jumia and Amazon, served by a Laravel API and refreshed every 30 seconds.",
};

/**
 * Root layout.
 *
 * QueryProvider is a client component, but wrapping the tree in it here does
 * NOT make every page a client component: children passed through a client
 * boundary keep rendering on the server. So the pages and product cards below
 * stay server-rendered while still having access to the query client.
 */
export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="flex min-h-full flex-col bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <QueryProvider>{children}</QueryProvider>
      </body>
    </html>
  );
}
