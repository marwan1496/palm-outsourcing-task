import { redirect } from "next/navigation";

/**
 * The app has one screen, so the root sends visitors straight to it.
 */
export default function Home() {
  redirect("/products");
}
