<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D.4 made `school_id` NOT NULL on all 15 tenant-scoped pas_*
 * tables. That works for the 10 Eloquent-managed tables (BelongsToTenant
 * trait auto-fills `school_id` on the `creating` event) but BREAKS the
 * 5 framework tables that Laravel writes to via raw query builder
 * inserts which bypass the trait entirely:
 *
 *   - pas_jobs           — Laravel queue insert
 *   - pas_failed_jobs    — Laravel queue failure insert
 *   - pas_notifications  — Laravel notifications system insert
 *   - pas_password_resets — Fortify password-reset insert (orphan but harmless)
 *   - pas_job_batches    — Laravel batched-job insert
 *
 * User hit this when dispatching a payroll-run batch:
 *   "SQLSTATE[HY000] [1364] Field 'school_id' doesn't have a default value
 *    ... insert into pas_job_batches ..."
 *
 * This migration reverts those 5 columns to nullable. The school_id is
 * still indexed and FK-constrained, so forensic queries by school still
 * work — just not all framework-side inserts populate it. The 10
 * Eloquent-managed tables (employee profiles, payroll runs, payslips,
 * audit logs, etc.) keep NOT NULL because their writes always go
 * through the trait.
 *
 * Long-term option: register Laravel queue/notification/batch
 * extensions that hook the inserts and inject the current tenant's
 * school_id. Out of scope for this hotfix — the columns are already
 * tagged at the schema level for any future audit query.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $frameworkTables = [
        'pas_jobs',
        'pas_failed_jobs',
        'pas_notifications',
        'pas_password_resets',
        'pas_job_batches',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->frameworkTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'school_id')) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` MODIFY `school_id` BIGINT UNSIGNED NULL");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Restoring NOT NULL would re-break framework inserts. The down()
        // path is intentionally a no-op — D.4's tighten was the wrong
        // shape for these tables, and we don't want to re-introduce the
        // bug on rollback.
        foreach ($this->frameworkTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'school_id')) {
                continue;
            }

            // Defensive: only re-tighten if the column is currently
            // nullable (the up() applied). Skip if something else
            // already changed it.
        }
    }
};
