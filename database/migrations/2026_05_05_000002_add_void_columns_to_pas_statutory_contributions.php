<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds soft-delete (void) tracking to the statutory contribution rule set.
 *
 * Voiding is the only sanctioned removal mechanism for a contribution row —
 * financial-adjacent records are never hard-deleted. The pair of columns
 * mirrors the audit semantic used elsewhere in the system:
 *
 *   - voided_at         — timestamp the row was retired; NULL while live.
 *   - voided_by_user_id — actor who retired it; nullable so an LMS user
 *     deletion (SET NULL via the FK) doesn't cascade-orphan the row.
 *
 * The accompanying model scope (`scopeNotVoided`) and the registry's
 * defense-in-depth chain ensure voided rows never participate in payroll
 * computation. The `voided_at` index is sized for the ubiquitous
 * `WHERE voided_at IS NULL` filter the registry runs on every active() call.
 *
 * Touches only `pas_statutory_contributions` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent column adds. The dev DB carries an out-of-band attempt at
        // these columns from a prior task draft (no FK, no index, no migration
        // row). Guarding each step lets `migrate` succeed cleanly whether the
        // table is in pristine post-Task-#10 shape or in the half-applied
        // state, without ever using `migrate:fresh` against the LMS-shared DB.
        Schema::table('pas_statutory_contributions', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_statutory_contributions', 'voided_at')) {
                $table->timestamp('voided_at')
                    ->nullable()
                    ->after('applies_to');
            }

            if (! Schema::hasColumn('pas_statutory_contributions', 'voided_by_user_id')) {
                // LMS `users.id` is `int unsigned` (legacy schema), not bigint —
                // so `foreignId()` would create a type mismatch. Match the
                // referenced column exactly with `unsignedInteger`.
                $table->unsignedInteger('voided_by_user_id')
                    ->nullable()
                    ->after('voided_at');
            } elseif ($this->columnIsBigint('pas_statutory_contributions', 'voided_by_user_id')) {
                // Half-applied state from a prior draft: column exists as
                // `bigint unsigned` and FK creation would fail. Narrow it to
                // `int unsigned` so the FK below can be added.
                $table->unsignedInteger('voided_by_user_id')->nullable()->change();
            }
        });

        // FK and index live outside the column-add closure so the column-existence
        // guard above doesn't suppress them when we hit a half-applied state.
        // The FK additionally requires the LMS-owned `users` table to be
        // present — on bootstrap of a fresh environment it may not be. Skip
        // FK creation in that case; it can be applied later via a follow-up
        // migration once LMS schema is in place.
        if (
            Schema::hasTable('users')
            && ! $this->hasForeignKey('pas_statutory_contributions', 'voided_by_user_id')
        ) {
            Schema::table('pas_statutory_contributions', function (Blueprint $table): void {
                $table->foreign('voided_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        if (! $this->hasIndex('pas_statutory_contributions', 'pas_statutory_contributions_voided_at_idx')) {
            Schema::table('pas_statutory_contributions', function (Blueprint $table): void {
                $table->index('voided_at', 'pas_statutory_contributions_voided_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('pas_statutory_contributions', function (Blueprint $table): void {
            if ($this->hasIndex('pas_statutory_contributions', 'pas_statutory_contributions_voided_at_idx')) {
                $table->dropIndex('pas_statutory_contributions_voided_at_idx');
            }

            if ($this->hasForeignKey('pas_statutory_contributions', 'voided_by_user_id')) {
                $table->dropForeign(['voided_by_user_id']);
            }

            $table->dropColumn(['voided_at', 'voided_by_user_id']);
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        // SQLite (test env) has no half-applied state — every run starts
        // from a clean schema — so we can safely report "not present" and
        // let the migration add the index normally. MySQL uses
        // information_schema for an exact lookup.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        $rows = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $indexName],
        );

        return $rows !== [];
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        $rows = DB::select(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
            [$table, $column],
        );

        return $rows !== [];
    }

    private function columnIsBigint(string $table, string $column): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        $rows = DB::select(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column],
        );

        return $rows !== [] && str_starts_with(strtolower((string) $rows[0]->COLUMN_TYPE), 'bigint');
    }
};
