<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D.4 tightened `school_id` to NOT NULL on every tenant-scoped pas_*
 * table — including `pas_audit_logs`. That was the wrong shape for the
 * audit table, for the same structural reason already fixed for the 5
 * framework tables in 2026_05_09_000001_relax_school_id_on_framework_tables:
 * legitimate writers produce rows that have no natural tenant.
 *
 * The audit pipeline (App\Observers\AuditObserver → App\Models\Pas\AuditLog
 * → BelongsToTenant trait) is structurally correct: when `Tenant::current()`
 * is null, the trait intentionally does NOT auto-fill `school_id`. The
 * three contexts where that fires are all legitimate:
 *
 *   1. Seeder-time global catalog inserts (StatutoryContributionSeeder
 *      writes PH BIR / SSS / PhilHealth / Pag-IBIG rates into the
 *      `pas_statutory_contributions` catalog, which deliberately does NOT
 *      use BelongsToTenant because contribution rates are national law,
 *      not per-school configuration). No HTTP request, no tenant context,
 *      and the audited model itself has no `school_id`.
 *
 *   2. Platform-admin global-catalog edits (users with `lms_user_id IS NULL`
 *      and the `platform-admin` role editing tenant-shared catalogs from
 *      a non-tenant-scoped context). Same shape: no natural school_id on
 *      the auditable row → no natural school_id on the audit entry.
 *
 *   3. Console/queue contexts (artisan commands, scheduled jobs) that
 *      mutate global catalogs outside any tenant-resolved web request.
 *
 * User hit (1) during `php artisan db:seed` on a fresh payroll_db_2:
 *   "SQLSTATE[HY000] [1364] Field 'school_id' doesn't have a default value
 *    ... insert into pas_audit_logs ..."
 *
 * This migration relaxes `pas_audit_logs.school_id` back to NULLABLE. The
 * column is still indexed and FK-constrained, so forensic queries by
 * school still work for the (overwhelmingly common) tenant-scoped audit
 * entries. The null rows are the audit trail for global-catalog mutations.
 *
 * No data backfill is needed: every row that survived D.4's ALTER already
 * has a non-null school_id, and the failed seeder insert never committed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('pas_audit_logs') || ! Schema::hasColumn('pas_audit_logs', 'school_id')) {
            return;
        }

        DB::statement('ALTER TABLE `pas_audit_logs` MODIFY `school_id` BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Re-tightening to NOT NULL would re-break audit writes for every
        // global-catalog mutation (seeders, platform-admin edits, console
        // contexts). The down() path is intentionally a no-op — D.4's
        // tighten was the wrong shape for this table, and a rollback must
        // not re-introduce the bug.
    }
};
