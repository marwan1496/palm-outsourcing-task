<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track scrape jobs so the UI can show what happened.
     *
     * Laravel's `jobs` table only holds work that hasn't run yet, and deletes
     * the row on success. So by the time you want to display "this URL was
     * scraped and here's the product", the queue has forgotten all about it.
     * This table is that memory.
     */
    public function up(): void
    {
        Schema::create('scrape_jobs', function (Blueprint $table): void {
            $table->id();

            // Groups the URLs from one submission, so the UI can show
            // "batch of 5: 3 done, 1 running, 1 failed".
            $table->uuid('batch_id')->index();

            // Matches products.source_url, so a job URL is always storable.
            $table->string('url', 512);

            $table->string('status', 16)->default('pending');

            // Set once the scrape succeeds. Nullable because most jobs have no
            // product yet, and nullOnDelete so deleting a product doesn't take
            // its job history with it.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Why it failed. Shown directly in the UI, so it needs to be
            // readable, not a stack trace.
            $table->text('error')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // The jobs list is "newest first, optionally filtered by status",
            // which is exactly this index.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_jobs');
    }
};
