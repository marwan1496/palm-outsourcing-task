import { NextResponse } from "next/server";

import { ApiError, retryScrapeJob } from "@/lib/api/client";

/**
 * POST /api/jobs/[id]/retry — put a failed job back on the queue.
 *
 * Useful precisely because scraping fails for reasons that pass: a site was
 * rate limiting, a proxy was down, Cloudflare was in a mood. Retrying is
 * usually the right first response, so it belongs one click away.
 */
export async function POST(
  _request: Request,
  // Next.js 16 hands route params in as a promise.
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;
  const jobId = Number(id);

  if (!Number.isInteger(jobId) || jobId <= 0) {
    return NextResponse.json({ message: "Invalid job id." }, { status: 400 });
  }

  try {
    await retryScrapeJob(jobId);

    return NextResponse.json(
      { message: "The job has been queued again." },
      { status: 202, headers: { "Cache-Control": "no-store" } },
    );
  } catch (error) {
    if (error instanceof ApiError) {
      // 409 means the job is not in a state that can be retried, which is
      // worth showing verbatim rather than as a generic failure.
      return NextResponse.json({ message: error.message }, { status: error.status });
    }

    console.error("Unexpected error retrying a job", error);

    return NextResponse.json(
      { message: "An unexpected error occurred while retrying the job." },
      { status: 500 },
    );
  }
}
