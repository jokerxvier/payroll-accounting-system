<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage A of the multi-tenant catalog conversion (deduction types + allowances).
 *
 * Mirrors the D.1 pattern from
 * 2026_05_08_000002_add_school_id_to_pas_tables.php for two catalog tables that
 * were intentionally left global in D.1 but are now being tenant-scoped per the
 * approved plan in /Users/jdev/.claude/plans/what-is-next-in-joyful-rabbit.md:
 *
 *   - pas_deduction_types
 *   - pas_allowances
 *
 * Per-stage scope (this migration is Stage A only):
 *   1. Add nullable `school_id` (with index + FK to pas_schools(id)).
 *   2. Backfill existing global rows to the default school.
 *   3. Convert the existing single-column `code` unique on each table into a
 *      composite `(school_id, code)` unique so two tenants can hold the same
 *      code (e.g. both schools' "rice_subsidy").
 *   4. After the FK is in place, clone the default school's catalog rows into
 *      every other existing school that currently has zero rows of its own —
 *      preserves "every tenant sees the same default catalog" until the user
 *      starts customising.
 *
 * Stages NOT in this migration (handled separately):
 *   - Stage B: BelongsToTenant trait on Allowance / DeductionType models.
 *   - Stage C: SchoolObserver to auto-clone catalogs for newly-created schools.
 *   - Stage D: FormRequest unique-rule updates.
 *   - Stage E: NOT NULL flip on school_id.
 *
 * The column is intentionally **nullable** in this slice — Stage E will tighten
 * to NOT NULL once Stage B/C/D have stabilised. No model trait, no global scope,
 * no enforcement is added here: existing queries continue to return all rows
 * because no scope filters them.
 *
 * Backfill safety:
 *   - Pulls the default school's id once via DB::scalar before the loop.
 *   - If the default school is missing (fresh-bootstrap dev / test env),
 *     backfill and per-tenant clone are silently skipped — columns stay null
 *     on existing rows but remain valid because they're nullable. New rows in
 *     those envs would also be null until Stage E enforces.
 *   - Backfill targets `WHERE school_id IS NULL` so re-runs never overwrite
 *     a row someone has manually re-tagged.
 *   - Per-tenant clone runs only when the target school has zero rows in the
 *     catalog — re-running this migration on a partially-customised tenant
 *     never overwrites that customisation.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, replace_unique: ?array<int,string>, replace_unique_name: ?string}>
     *
     * `replace_unique`: column list of an existing single-column unique to
     *   drop and recreate as `(school_id, ...columns)`.
     * `replace_unique_name`: explicit index name when the existing migration
     *   passed a custom name to `->unique()`. null = use Laravel's deterministic
     *   `{table}_{columns}_unique` naming. Both create migrations call
     *   `->unique()` with no custom name, so Laravel auto-named them
     *   `pas_allowances_code_unique` and `pas_deduction_types_code_unique`.
     */
    private array $tables = [
        ['table' => 'pas_deduction_types', 'replace_unique' => ['code'], 'replace_unique_name' => null],
        ['table' => 'pas_allowances', 'replace_unique' => ['code'], 'replace_unique_name' => null],
    ];

    public function up(): void
    {
        // Defensive — pas_schools must exist (Phase B.1 ships ahead of this).
        if (! Schema::hasTable('pas_schools')) {
            return;
        }

        // Pull the default school id once; reused across every UPDATE and the
        // per-tenant clone step. Returns null if the seeder hasn't run yet
        // (e.g. RefreshDatabase in tests). Backfill + clone are skipped in
        // that case; the columns stay null because they're nullable.
        $defaultSchoolId = DB::table('pas_schools')
            ->where('slug', 'default')
            ->value('id');

        // No `DB::transaction` here — MySQL implicitly commits each DDL
        // statement (ALTER TABLE ... ADD/DROP), so wrapping the migration in
        // an explicit transaction is misleading at best (the DDL has already
        // committed when the wrapper rolls back) and produces spurious
        // commit-time errors at worst. Each helper below is individually
        // idempotent so a partial-failure re-run lands cleanly.
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

        // After the FK + composite-unique are in place, clone the default
        // school's catalog rows into every other school that currently has
        // zero rows. Preserves "every tenant sees the same default catalog"
        // until the user starts customising.
        if ($defaultSchoolId !== null) {
            $this->cloneCatalogsForNonDefaultSchools((int) $defaultSchoolId);
        }
    }

    public function down(): void
    {
        // Reverse in inverse order — restore the original single-column uniques
        // first, then drop the school_id column (and its FK + index). Cloned
        // rows are intentionally NOT removed: they're indistinguishable from
        // any rows the user has since edited, and a blanket DELETE would
        // discard customisations. The `code` column is no longer globally
        // unique after the down(), so duplicate codes from the clone step
        // would block restoring the single-column unique. Caller must
        // de-duplicate manually before rollback.
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
     * MySQL) a FK to `pas_schools(id)`. The column is positioned after `id`.
     *
     * Backfill runs BEFORE the FK is added so the constraint check doesn't
     * fail mid-migration on rows that haven't been backfilled yet.
     */
    private function addSchoolIdColumn(string $table, ?int $defaultSchoolId): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'school_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('school_id')->nullable()->after('id');
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
        // on Laravel to noop if the FK is absent.
        $hasFk = Schema::getConnection()->getDriverName() !== 'mysql'
            || $this->hasForeignKey($table, 'school_id');

        if ($hasFk) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['school_id']);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->dropIndex($table.'_school_id_index');
            $blueprint->dropColumn('school_id');
        });
    }

    /**
     * Drop the existing single-column unique on `$columns` and create a
     * composite `(school_id, ...$columns)` unique in its place. Idempotent:
     * if the migration is re-run after a partial-completion crash on MySQL
     * (DDL implicitly commits, so re-runs are a real possibility), we only
     * drop the single-column unique when it's still present and only add the
     * composite when it's missing.
     */
    private function convertUniqueToComposite(
        string $table,
        array $columns,
        ?string $existingName,
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        $indexes = collect(Schema::getIndexes($table));

        $singleColumnUniqueExists = $indexes->contains(
            fn (array $idx): bool => $idx['columns'] === $columns && $idx['unique'],
        );
        $compositeUniqueExists = $indexes->contains(
            fn (array $idx): bool => $idx['columns'] === array_merge(['school_id'], $columns) && $idx['unique'],
        );

        Schema::table($table, function (Blueprint $blueprint) use (
            $columns,
            $existingName,
            $singleColumnUniqueExists,
            $compositeUniqueExists,
        ): void {
            if ($singleColumnUniqueExists) {
                if ($existingName !== null) {
                    $blueprint->dropUnique($existingName);
                } else {
                    $blueprint->dropUnique($columns);
                }
            }

            if (! $compositeUniqueExists) {
                $blueprint->unique(array_merge(['school_id'], $columns));
            }
        });
    }

    /**
     * Inverse of convertUniqueToComposite — drops the composite and restores
     * the original single-column unique with its original name (when
     * specified) or auto-naming. Idempotent for partial-rollback re-runs.
     */
    private function revertCompositeToSingleUnique(
        string $table,
        array $columns,
        ?string $existingName,
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        $indexes = collect(Schema::getIndexes($table));

        $compositeUniqueExists = $indexes->contains(
            fn (array $idx): bool => $idx['columns'] === array_merge(['school_id'], $columns) && $idx['unique'],
        );
        $singleColumnUniqueExists = $indexes->contains(
            fn (array $idx): bool => $idx['columns'] === $columns && $idx['unique'],
        );

        Schema::table($table, function (Blueprint $blueprint) use (
            $columns,
            $existingName,
            $compositeUniqueExists,
            $singleColumnUniqueExists,
        ): void {
            if ($compositeUniqueExists) {
                $blueprint->dropUnique(array_merge(['school_id'], $columns));
            }

            if (! $singleColumnUniqueExists) {
                if ($existingName !== null) {
                    $blueprint->unique($columns, $existingName);
                } else {
                    $blueprint->unique($columns);
                }
            }
        });
    }

    /**
     * For every school other than the default, copy each of the default
     * school's catalog rows into that school — but ONLY when the target
     * school has zero rows in that catalog. Idempotent: running this again
     * is a no-op once each school has at least one row.
     *
     * Inserts go through the query builder so the (already-loaded) Eloquent
     * models — which don't yet have BelongsToTenant in this slice — are
     * bypassed entirely. `id` is regenerated by auto-increment; timestamps
     * are stamped at clone time.
     */
    private function cloneCatalogsForNonDefaultSchools(int $defaultSchoolId): void
    {
        $now = now();

        $otherSchools = DB::table('pas_schools')
            ->where('id', '!=', $defaultSchoolId)
            ->orderBy('id')
            ->pluck('id');

        if ($otherSchools->isEmpty()) {
            return;
        }

        foreach ($this->tables as $config) {
            $table = $config['table'];

            if (! Schema::hasTable($table)) {
                continue;
            }

            $defaultRows = DB::table($table)
                ->where('school_id', $defaultSchoolId)
                ->get();

            if ($defaultRows->isEmpty()) {
                continue;
            }

            foreach ($otherSchools as $targetSchoolId) {
                $existingCount = DB::table($table)
                    ->where('school_id', $targetSchoolId)
                    ->count();

                if ($existingCount > 0) {
                    // The school already has its own rows — skip to preserve
                    // any customisation that might already be in place.
                    continue;
                }

                $insertRows = $defaultRows->map(function (object $row) use ($targetSchoolId, $now): array {
                    $clone = (array) $row;
                    unset($clone['id']);
                    $clone['school_id'] = $targetSchoolId;
                    $clone['created_at'] = $now;
                    $clone['updated_at'] = $now;

                    return $clone;
                })->all();

                DB::table($table)->insert($insertRows);
            }
        }
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
