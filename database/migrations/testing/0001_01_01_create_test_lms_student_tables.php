<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-scoped `sm_students` and `sm_parents`.
 *
 * The real tables are owned by the LMS and read-only at this app's boundary,
 * so tests cannot seed them — `RefreshDatabase` would have to truncate live
 * school data. This recreates a sqlite-compatible subset for tests that call
 * `useLmsSqliteMirror()`, exactly as the `users` mirror beside it does.
 *
 * **Without this, the sibling de-duplication cannot be tested at all.** The
 * live LMS holds one student and one parent, so `GROUP BY parent_id` returns a
 * single group of one — the case the import exists to handle (two children,
 * one payer) is unreachable against real data. These fixtures are the only
 * place that behaviour can be exercised.
 *
 * Only the columns the import reads are here; the real `sm_students` has 56.
 *
 * Lives OUTSIDE database/migrations/ so `MigrationSafetyTest`'s `pas_`-prefix
 * rule does not flag it, and is loaded by `TestingMigrationsServiceProvider`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sm_students')) {
            Schema::create('sm_students', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('full_name')->nullable();
                $table->string('email')->nullable();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->boolean('active_status')->default(true);
                $table->unsignedBigInteger('school_id')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sm_parents')) {
            Schema::create('sm_parents', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('fathers_name')->nullable();
                $table->string('fathers_mobile')->nullable();
                $table->string('mothers_name')->nullable();
                $table->string('mothers_mobile')->nullable();
                $table->string('guardians_name')->nullable();
                $table->string('guardians_mobile')->nullable();
                $table->string('guardians_email')->nullable();
                $table->string('guardians_relation', 30)->nullable();
                $table->string('guardians_address')->nullable();
                $table->boolean('is_guardian')->nullable();
                $table->boolean('active_status')->default(true);
                $table->unsignedBigInteger('school_id')->default(1);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_students');
        Schema::dropIfExists('sm_parents');
    }
};
