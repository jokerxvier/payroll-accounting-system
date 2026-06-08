<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D.4 of the multi-tenant refactor — flip `school_id` to NOT NULL on the
 * 15 tenant-scoped `pas_*` tables.
 *
 * D.1 added the column as nullable and backfilled. D.2 wired the
 * `BelongsToTenant` trait (auto-fills school_id on creating). D.3 added the
 * composite unique on `pas_users(school_id, lms_user_id)`. By D.4 every write
 * path goes through a tenant context, so NULL is no longer a valid state for
 * tenant-scoped rows. This migration removes the "nullable escape hatch":
 * after it runs, any insert that omits school_id (or sets it to NULL) will
 * fail at the database layer, not silently leak across tenant boundaries.
 *
 * Defensive backfill:
 *   - Before the ALTER, we attempt one last backfill of any leftover NULL
 *     school_id rows to the seeded default school. This handles the case
 *     where rows were inserted between D.1 (which only backfilled rows that
 *     existed at D.1 migrate-time) and D.4 — for example, factories that
 *     bypassed BelongsToTenant in earlier dev sessions, or LMS-side data
 *     replicated between the two slices.
 *   - If after the backfill any row still has a NULL school_id (e.g. the
 *     default school was deleted, or rows reference a school that's gone),
 *     the migration aborts with a clear error rather than failing mid-ALTER.
 *
 * Driver split:
 *   - On MySQL we issue a raw `ALTER TABLE ... MODIFY school_id BIGINT
 *     UNSIGNED NOT NULL` per table. Schema::table()->change() requires
 *     doctrine/dbal, which we don't depend on, so the raw ALTER is the
 *     cleanest path.
 *   - On sqlite we skip the ALTER entirely. The test env always runs against
 *     a fresh in-memory sqlite via RefreshDatabase, and the column starts
 *     nullable in D.1's migration; sqlite's ALTER TABLE doesn't natively
 *     support changing a column's nullability without a full table rebuild.
 *     Tests assert the post-D.4 invariant via the migration test (MySQL only)
 *     and the BelongsToTenant trait already auto-fills school_id on every
 *     creating event, so sqlite's nullable column is never observed as NULL
 *     in practice.
 *
 * Touches only `pas_*` — guardrail compliant.
 *
 * Tables NOT touched (intentionally, per the plan):
 *   pas_users           — platform admins legitimately have school_id NULL
 *   pas_schools         — the registry itself
 *   pas_allowances,
 *   pas_deduction_types,
 *   pas_statutory_contributions
 *                       — global catalog; no school_id column.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'pas_employee_profiles',
        'pas_pay_periods',
        'pas_payroll_runs',
        'pas_payslips',
        'pas_employee_allowances',
        'pas_employee_deductions',
        'pas_employee_loans',
        'pas_payroll_adjustments',
        'pas_audit_logs',
        'pas_accounting_periods',
        'pas_jobs',
        'pas_failed_jobs',
        'pas_notifications',
        'pas_password_resets',
        'pas_job_batches',
    ];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Last-chance backfill — if the default school exists, sweep any
        // straggler NULL school_id rows. Safe under re-run (whereNull guard
        // means already-tagged rows are untouched).
        $defaultSchoolId = null;
        if (Schema::hasTable('pas_schools')) {
            $defaultSchoolId = DB::table('pas_schools')
                ->where('slug', 'default')
                ->value('id');
        }

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'school_id')) {
                continue;
            }

            if ($defaultSchoolId !== null) {
                DB::table($table)
                    ->whereNull('school_id')
                    ->update(['school_id' => (int) $defaultSchoolId]);
            }

            // Pre-flight — any remaining NULL row means we cannot safely
            // ALTER. Abort with an actionable error.
            $remaining = DB::table($table)->whereNull('school_id')->count();
            if ($remaining > 0) {
                throw new RuntimeException(sprintf(
                    'D.4 abort: %s still has %d row(s) with NULL school_id. Run SchoolSeeder, '
                    .'verify the default school exists in pas_schools (slug=default), and re-run '
                    .'the migration. If those rows reference a deleted tenant, manually backfill '
                    .'them first.',
                    $table,
                    $remaining,
                ));
            }

            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE `{$table}` MODIFY `school_id` BIGINT UNSIGNED NOT NULL");
            }
            // sqlite: skip — column already nullable, fresh migrate recreates
            // tables for tests, and the BelongsToTenant trait + the migration
            // test gate keep the invariant honest in CI.
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'mysql') {
            return; // Symmetric to up(): no-op on sqlite.
        }

        foreach (array_reverse($this->tables) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'school_id')) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` MODIFY `school_id` BIGINT UNSIGNED NULL");
        }
    }
};
