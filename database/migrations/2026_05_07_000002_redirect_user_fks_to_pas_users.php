<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A.3 — redirect every FK that today references the LMS-owned `users`
 * table so it instead references the payroll-owned `pas_users` table created
 * in A.1. Data integrity is preserved without any row-level migration because
 * the A.1 backfill is id-preserving (pas_users.id == users.id) and the A.2
 * Fortify::authenticateUsing upsert maintains that invariant for any LMS user
 * that logs in post-backfill.
 *
 * Five FK constraints are redirected (all `nullOnDelete()` semantics preserved):
 *
 *   pas_statutory_contributions.voided_by_user_id
 *   pas_payroll_runs.approved_by_user_id
 *   pas_payroll_runs.voided_by_user_id
 *   pas_payroll_runs.submitted_by_user_id
 *   pas_payroll_runs.posted_by_user_id
 *
 * Column-type widening:
 * Each of the five columns was originally declared `unsignedInteger`
 * (`int unsigned`) to match the legacy LMS `users.id` shape. The new target
 * `pas_users.id` is `bigint unsigned` (`bigIncrements`). MySQL refuses to
 * create an FK across incompatible integer widths, so we widen each column
 * to `unsignedBigInteger` before adding the FK. The widening is a strict
 * superset (`int unsigned` max ~4.3B fits inside `bigint unsigned`), so all
 * existing values — every one of which was sourced from LMS `users.id` and
 * therefore well within `int unsigned` range — survive unchanged. SQLite
 * collapses both to a single INTEGER affinity, so the change is a no-op
 * under tests.
 *
 * `pas_audit_logs.actor_id` is intentionally unconstrained (audit logs are
 * append-only and the actor may live across connections in future tenants),
 * so it is NOT touched here.
 *
 * After this migration ships:
 *   - The `Schema::hasTable('users')` guards in the original FK-creation
 *     migrations (2026_05_05_000002, 000004, 000007) are orphaned but harmless.
 *     A separate cleanup PR can remove them.
 *   - Phase A is complete; the auth pivot is fully decoupled from LMS.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, column: string, on_delete: string}>
     */
    private array $fks = [
        ['table' => 'pas_statutory_contributions', 'column' => 'voided_by_user_id', 'on_delete' => 'set null'],
        ['table' => 'pas_payroll_runs', 'column' => 'approved_by_user_id', 'on_delete' => 'set null'],
        ['table' => 'pas_payroll_runs', 'column' => 'voided_by_user_id', 'on_delete' => 'set null'],
        ['table' => 'pas_payroll_runs', 'column' => 'submitted_by_user_id', 'on_delete' => 'set null'],
        ['table' => 'pas_payroll_runs', 'column' => 'posted_by_user_id', 'on_delete' => 'set null'],
    ];

    public function up(): void
    {
        // Defensive — A.1 must have created pas_users first.
        if (! Schema::hasTable('pas_users')) {
            return;
        }

        foreach ($this->fks as $fk) {
            $this->repointForeignKey($fk['table'], $fk['column'], 'pas_users', $fk['on_delete']);
        }
    }

    public function down(): void
    {
        // Reverse: point each FK back at users(id). Same defensive pattern.
        // The original FK-creation migrations gated FK creation on
        // `Schema::hasTable('users')`, so mirror that here — if the LMS
        // `users` table is unavailable when rolling back (e.g., a fresh
        // bootstrap), simply drop the FK and leave the column unconstrained.
        foreach ($this->fks as $fk) {
            $this->repointForeignKey(
                $fk['table'],
                $fk['column'],
                Schema::hasTable('users') ? 'users' : null,
                $fk['on_delete'],
            );
        }
    }

    /**
     * Drop the existing FK on (`$table`.`$column`) and recreate it pointing at
     * `$targetTable`.`id` with the same on-delete semantic. When `$targetTable`
     * is null, the FK is dropped without recreation (used by `down()` when the
     * LMS `users` table is unavailable).
     *
     * The column is also widened to / narrowed back to the type that matches
     * the FK target's id column (`bigint unsigned` for pas_users,
     * `int unsigned` for the legacy LMS users). Done in two separate
     * `Schema::table()` calls so the column type alteration commits before
     * the FK is added, avoiding the MySQL "incompatible types" error
     * (errno 3780).
     */
    private function repointForeignKey(
        string $table,
        string $column,
        ?string $targetTable,
        string $onDelete,
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        // Step 1: drop the existing FK (if any) and align the column type
        // with the new target id column. Run as its own ALTER so the type
        // change commits before the FK gets added below.
        Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $targetTable): void {
            if ($this->hasForeignKey($table, $column)) {
                $blueprint->dropForeign([$column]);
            }

            // MySQL is the only driver where the int-vs-bigint mismatch
            // matters; sqlite collapses both to INTEGER affinity. Skip the
            // type-change call on non-MySQL so we don't depend on doctrine/dbal
            // for `change()` under tests.
            if (DB::connection()->getDriverName() !== 'mysql') {
                return;
            }

            if ($targetTable === 'pas_users') {
                $blueprint->unsignedBigInteger($column)->nullable()->change();
            } elseif ($targetTable === 'users') {
                $blueprint->unsignedInteger($column)->nullable()->change();
            }
        });

        if ($targetTable === null) {
            return;
        }

        // Step 2: add the new FK against the now-correctly-typed column.
        Schema::table($table, function (Blueprint $blueprint) use ($column, $targetTable, $onDelete): void {
            $foreign = $blueprint->foreign($column)
                ->references('id')
                ->on($targetTable);

            match ($onDelete) {
                'set null' => $foreign->nullOnDelete(),
                'restrict' => $foreign->restrictOnDelete(),
                'cascade' => $foreign->cascadeOnDelete(),
                default => $foreign,
            };
        });
    }

    /**
     * Driver-portable check for "does column $column on $table participate in
     * any foreign-key constraint?". MySQL uses information_schema; other
     * drivers (notably sqlite under tests) fall back to `Schema::getForeignKeys`,
     * which Laravel implements via PRAGMA queries on sqlite.
     */
    private function hasForeignKey(string $table, string $column): bool
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $database = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$database, $table, $column],
            );

            return (int) ($row->c ?? 0) > 0;
        }

        foreach (Schema::getForeignKeys($table) as $fk) {
            if (in_array($column, $fk['columns'], true)) {
                return true;
            }
        }

        return false;
    }
};
