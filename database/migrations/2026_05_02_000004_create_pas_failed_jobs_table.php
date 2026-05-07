<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App-owned mirror of Laravel's standard failed_jobs table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_failed_jobs')) {
            return;
        }

        Schema::create('pas_failed_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_failed_jobs');
    }
};
