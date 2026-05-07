<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App-owned mirror of Laravel's standard jobs table, prefixed to avoid
 * colliding with the LMS's framework tables in the shared physical DB.
 *
 * Redis is the primary queue driver; this exists so the database driver
 * remains usable for fallback scenarios (and so artisan queue commands
 * pointing at the database connection have a valid table).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_jobs')) {
            return;
        }

        Schema::create('pas_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_jobs');
    }
};
