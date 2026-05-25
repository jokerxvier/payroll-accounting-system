<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage E of the multi-tenant catalog conversion — flip `school_id` to NOT NULL
 * on the two catalog tables (pas_deduction_types, pas_allowances).
 *
 * Stage A added the column as nullable and backfilled. Stage B wired the
 * `BelongsToTenant` trait (auto-fills school_id on creating). Stage C added the
 * SchoolObserver clone hook. Stage D updated FormRequest unique rules. Stage F
 * pinned model-level coverage. By Stage E every write path goes through a
 * tenant context, so NULL is no longer a valid state for these catalog rows.
 * This migration removes the "nullable escape hatch": after it runs, any insert
 * that omits school_id (or sets it to NULL) will fail at the database layer,
 * not silently leak across tenant boundaries.
 *
 * Defensive backfill:
 *   - Before the ALTER, we attempt one last backfill of any leftover NULL
 *     school_id rows to the seeded default school. This handles the case where
 *     rows were inserted between Stage A (which only backfilled rows that
 *     existed at Stage-A migrate-time) and Stage E — for example, factories
 *     that bypassed BelongsToTenant during earlier dev sessions.
 *   - If after the backfill any row still has a NULL school_id (e.g. the
 *     default school was deleted, or rows reference a school that's gone), the
 *     migration aborts with a clear error rather than failing mid-ALTER.
 *
 * Driver split:
 *   - On MySQL we issue a raw `ALTER TABLE ... MODIFY school_id BIGINT
 *     UNSIGNED NOT NULL` per table. Schema::table()->change() requires
 *     doctrine/dbal, which we don't depend on, so the raw ALTER is the
 *     cleanest path. Mirrors 2026_05_08_000004_pas_school_id_not_null.php.
 *   - On sqlite we skip the ALTER entirely. The test env always runs against a
 *     fresh in-memory sqlite via RefreshDatabase, and the column starts
 *     nullable in Stage A's migration; sqlite's ALTER TABLE doesn't natively
 *     support changing a column's nullability without a full table rebuild.
 *     The BelongsToTenant trait already auto-fills school_id on every creating
 *     event, so sqlite's nullable column is never observed as NULL in
 *     practice.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'pas_deduction_types',
        'pas_allowances',
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
                    'Stage E abort: %s still has %d row(s) with NULL school_id. Run SchoolSeeder, '
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
