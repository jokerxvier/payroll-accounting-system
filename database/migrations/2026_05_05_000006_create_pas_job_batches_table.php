<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App-owned mirror of Laravel's standard `job_batches` table, prefixed to
 * avoid colliding with the LMS's framework tables in the shared physical DB.
 *
 * Bus::batch() requires this table for its metadata (then/catch callbacks,
 * job counts, cancellation flag). The accompanying `config/queue.php` change
 * points the `batching.table` config key at `pas_job_batches`.
 *
 * Schema mirrors `php artisan queue:batches-table`'s default exactly, so any
 * future Laravel upgrade that touches the schema can be rebased cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_job_batches')) {
            return;
        }

        Schema::create('pas_job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_job_batches');
    }
};
