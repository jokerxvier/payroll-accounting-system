<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D.1 of the multi-tenant refactor — schema-layer-only slice.
 *
 * Adds a `school_id` column to every tenant-scoped `pas_*` table, backfills it
 * from the default school for any pre-existing rows, and converts three
 * single-column unique constraints (lms_staff_id, pay-period code, accounting-
 * period code) into composite (school_id, X) uniques so two tenants can share
 * the same value.
 *
 * The column is intentionally **nullable** in this slice — D.4 will tighten to
 * NOT NULL once D.2's global scope and D.3's `pas_users` restructure have
 * stabilised. No model trait, no global scope, no enforcement is added here:
 * existing queries continue to return all rows because no scope filters them.
 *
 * Tables NOT touched (intentionally global per plan-2.md):
 *   pas_allowances, pas_deduction_types, pas_statutory_contributions
 *   (catalog rows apply to every school)
 *
 * Tables NOT touched (handled by other slices):
 *   pas_users (D.3)
 *   pas_schools (the registry itself)
 *
 * Backfill safety:
 *   - Pulls the default school's id once via DB::scalar before the loop.
 *   - If the default school is missing (fresh-bootstrap dev / test env),
 *     backfill is silently skipped — columns stay null on existing rows but
 *     remain valid because they're nullable. New rows in those envs would
 *     also be null until D.2/D.4 enforce.
 *   - Backfill targets `WHERE school_id IS NULL` so re-runs never overwrite
 *     a row someone has manually re-tagged.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, replace_unique: ?array<int,string>, replace_unique_name: ?string}>
     *
     * `replace_unique`: column list of an existing single-column unique to
     *   drop and recreate as `(school_id, ...columns)`. null = no unique to swap.
     * `replace_unique_name`: explicit index name when the existing migration
     *   passed a custom name to `->unique()`. null = use Laravel's deterministic
     *   `{table}_{columns}_unique` naming.
     */
    private array $tables = [
        ['table' => 'pas_employee_profiles', 'replace_unique' => ['lms_staff_id'], 'replace_unique_name' => 'pas_employee_profiles_lms_staff_id_unique'],
        ['table' => 'pas_pay_periods', 'replace_unique' => ['code'], 'replace_unique_name' => null],
        ['table' => 'pas_accounting_periods', 'replace_unique' => ['code'], 'replace_unique_name' => null],
        ['table' => 'pas_payroll_runs', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_payslips', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_employee_allowances', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_employee_deductions', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_employee_loans', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_payroll_adjustments', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_audit_logs', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_jobs', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_failed_jobs', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_notifications', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_password_resets', 'replace_unique' => null, 'replace_unique_name' => null],
        ['table' => 'pas_job_batches', 'replace_unique' => null, 'replace_unique_name' => null],
    ];

    public function up(): void
    {
        // Defensive — pas_schools must exist (Phase B.1 ships ahead of this).
        if (! Schema::hasTable('pas_schools')) {
            return;
        }

        // Pull the default school id once; reused across every UPDATE.
        // Returns null if the seeder hasn't run yet (e.g. RefreshDatabase in
        // tests). Backfill is skipped in that case; the columns stay null
        // because they're nullable in this slice.
        $defaultSchoolId = DB::table('pas_schools')
            ->where('slug', 'default')
            ->value('id');

        foreach ($this->tables as $config) {
            $this->addSchoolIdColumn(
                $config['table'],
                $defaultSchoolId !== null ? (int) $defaultSchoolId : null,
            );
            if ($config['replace_unique'] !== null) {
                $this->convertUniqueToComposite(
                    $config['table'],
                    $config['replace_unique'],
                    $config['replace_unique_name'],
                );
            }
        }
    }

    public function down(): void
    {
        // Reverse in inverse order — restore the original single-column uniques
        // first, then drop the school_id column (and its FK + index).
        foreach (array_reverse($this->tables) as $config) {
            if ($config['replace_unique'] !== null) {
                $this->revertCompositeToSingleUnique(
                    $config['table'],
                    $config['replace_unique'],
                    $config['replace_unique_name'],
                );
            }
            $this->dropSchoolIdColumn($config['table']);
        }
    }

    /**
     * Add a nullable `school_id` column to `$table` with an index and (on
     * MySQL) a FK to `pas_schools(id)`. The column is positioned after `id`
     * when that column exists; otherwise Schema appends at the end (notably
     * for `pas_password_resets` which has no `id`).
     *
     * Backfill runs BEFORE the FK is added so the constraint check doesn't
     * fail mid-migration on rows that haven't been backfilled yet.
     */
    private function addSchoolIdColumn(string $table, ?int $defaultSchoolId): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'school_id')) {
            return;
        }

        $hasIdColumn = Schema::hasColumn($table, 'id');

        Schema::table($table, function (Blueprint $blueprint) use ($hasIdColumn): void {
            $column = $blueprint->unsignedBigInteger('school_id')->nullable();
            if ($hasIdColumn) {
                $column->after('id');
            }
            $blueprint->index('school_id');
        });

        if ($defaultSchoolId !== null) {
            DB::table($table)
                ->whereNull('school_id')
                ->update(['school_id' => $defaultSchoolId]);
        }

        // Add the FK constraint after the backfill so the constraint check
        // doesn't fail mid-migration on rows that haven't been backfilled yet.
        // The has-FK guard is MySQL-only (information_schema), so re-runs of
        // this migration on sqlite would re-add the FK; sqlite raises a
        // duplicate-FK error in that case but practically we never re-run
        // up() against an in-memory test DB — RefreshDatabase always starts
        // fresh — so the lack of an idempotency check on sqlite is fine.
        $alreadyHasFk = Schema::getConnection()->getDriverName() === 'mysql'
            && $this->hasForeignKey($table, 'school_id');

        if (! $alreadyHasFk) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreign('school_id')
                    ->references('id')
                    ->on('pas_schools')
                    ->restrictOnDelete();
            });
        }
    }

    private function dropSchoolIdColumn(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'school_id')) {
            return;
        }

        // hasForeignKey is MySQL-only — sqlite cannot introspect via
        // information_schema. On sqlite we simply attempt the drop and rely
        // on Laravel to noop if the FK is absent (Schema::table with a
        // dropForeign on a missing FK raises on MySQL, so we keep the guard
        // for MySQL but skip it on sqlite).
        $hasFk = Schema::getConnection()->getDriverName() !== 'mysql'
            || $this->hasForeignKey($table, 'school_id');

        if ($hasFk) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['school_id']);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            // Laravel's auto-named index from `->index('school_id')` is
            // `{table}_school_id_index`.
            $blueprint->dropIndex($table.'_school_id_index');
            $blueprint->dropColumn('school_id');
        });
    }

    /**
     * Drop the existing single-column unique on `$columns` and create a
     * composite `(school_id, ...$columns)` unique in its place.
     */
    private function convertUniqueToComposite(
        string $table,
        array $columns,
        ?string $existingName,
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $existingName): void {
            // Drop the existing single-column unique. If the original migration
            // passed a custom name to `->unique()`, that's what's on disk and
            // Laravel can't infer it from the column list.
            if ($existingName !== null) {
                $blueprint->dropUnique($existingName);
            } else {
                $blueprint->dropUnique($columns);
            }

            // Create the composite unique. Laravel's auto-naming yields
            // `{table}_school_id_{column}_unique`.
            $blueprint->unique(array_merge(['school_id'], $columns));
        });
    }

    /**
     * Inverse of convertUniqueToComposite — drops the composite and restores
     * the original single-column unique with its original name (when
     * specified) or auto-naming.
     */
    private function revertCompositeToSingleUnique(
        string $table,
        array $columns,
        ?string $existingName,
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $existingName): void {
            // Drop the composite Laravel created from
            // `(school_id, ...columns)`.
            $blueprint->dropUnique(array_merge(['school_id'], $columns));

            // Restore the original single-column unique. If the original
            // migration passed a custom name, restore it; otherwise let
            // Laravel auto-name.
            if ($existingName !== null) {
                $blueprint->unique($columns, $existingName);
            } else {
                $blueprint->unique($columns);
            }
        });
    }

    /**
     * MySQL-specific: does column $column on $table participate in any FK?
     * Used to keep up()/down() idempotent across re-runs.
     */
    private function hasForeignKey(string $table, string $column): bool
    {
        $database = DB::getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, $table, $column],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
