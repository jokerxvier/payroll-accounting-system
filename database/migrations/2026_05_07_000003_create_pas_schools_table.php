<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B.1 of the multi-tenant refactor.
 *
 * Establishes the tenant registry — one row per LMS deployment served by
 * this central payroll instance. Phase C uses these rows to dynamically
 * rebind `config('database.connections.lms')` per request via the custom
 * SwitchLmsConnection task.
 *
 * Columns of note:
 *   - slug        — URL-safe identifier; used by the path-prefix finder in
 *                   Phase C if subdomain isn't picked. Always unique.
 *   - domain      — the subdomain or full domain that resolves to this
 *                   tenant. Optional; unique when not null. Spatie's
 *                   DomainTenantFinder reads this column.
 *   - lms_db_*    — the LMS connection credentials for this tenant. Stored
 *                   encrypted at rest (text column to fit the encrypted
 *                   payload, which exceeds 255 chars). The `encrypted` cast
 *                   on `App\Models\Pas\School::lms_db_password` round-trips
 *                   plaintext through APP_KEY-bound encryption.
 *   - is_active   — Phase C's scheduler iterates active tenants only.
 *
 * No FK references this table yet; Phase D adds `school_id` to every
 * tenant-scoped `pas_*` table and points its FK here. Touches only `pas_*`
 * — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_schools')) {
            return;
        }

        Schema::create('pas_schools', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('slug');
            $table->string('domain')->nullable();
            $table->string('lms_db_host')->default('127.0.0.1');
            $table->unsignedSmallInteger('lms_db_port')->default(3306);
            $table->string('lms_db_database');
            $table->string('lms_db_username');
            // Encrypted at rest via the model cast. `text` (not `string`)
            // because Laravel's encrypted payload is base64 + envelope and
            // routinely exceeds the 255-char varchar default.
            $table->text('lms_db_password');
            $table->string('lms_db_charset', 32)->default('utf8mb4');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('slug', 'pas_schools_slug_uq');
            $table->unique('domain', 'pas_schools_domain_uq');
            $table->index('is_active', 'pas_schools_is_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_schools');
    }
};
