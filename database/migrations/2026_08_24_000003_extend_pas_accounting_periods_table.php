<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 1 — promote `pas_accounting_periods` from an unused
 * primitive into the real period-locking table.
 *
 * The table shipped in Phase 1 (2026_05_02_000002) as a placeholder and
 * gained `school_id` in the Phase-D tenancy pass, but nothing ever wrote to
 * it: there is no model, no controller, no UI, and no code path that reads
 * it. It is therefore empty in every environment, which is why the columns
 * below can be added and the actor column renamed without a backfill.
 *
 * Changes:
 *   - `closed_by` → `closed_by_user_id`, matching the `*_by_user_id` actor
 *     convention used by pas_payroll_runs and pas_statutory_contributions.
 *   - FK from that column to `pas_users` (Phase A.3 redirected every actor
 *     FK off the LMS `users` table; this one was missed because the table
 *     was dormant).
 *   - `reopened_at` / `reopened_by_user_id` — reopening a closed period is
 *     the single most audit-sensitive action in the module, so who did it
 *     and when is recorded on the row itself rather than left to the audit
 *     log alone.
 *   - `name` — human label ("August 2026") shown in period pickers, kept
 *     separate from the machine `code` ("2026-08").
 *   - `fiscal_year` — needed by the Statement of Changes in Equity and the
 *     year-end retained-earnings roll-forward, which group periods by
 *     fiscal year rather than by calendar date range.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_accounting_periods')) {
            return;
        }

        if (Schema::hasColumn('pas_accounting_periods', 'closed_by')) {
            Schema::table('pas_accounting_periods', function (Blueprint $table): void {
                $table->renameColumn('closed_by', 'closed_by_user_id');
            });
        }

        Schema::table('pas_accounting_periods', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_accounting_periods', 'name')) {
                $table->string('name', 120)->nullable()->after('code');
            }
            if (! Schema::hasColumn('pas_accounting_periods', 'fiscal_year')) {
                $table->unsignedSmallInteger('fiscal_year')->nullable()->after('end_date');
            }
            if (! Schema::hasColumn('pas_accounting_periods', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable();
            }
            if (! Schema::hasColumn('pas_accounting_periods', 'reopened_by_user_id')) {
                $table->unsignedBigInteger('reopened_by_user_id')->nullable();
            }
        });

        // Actor FKs are added only when the target table exists, mirroring
        // the defensive guard the Phase-1 migrations use for bootstrap envs.
        if (Schema::hasTable('pas_users')) {
            Schema::table('pas_accounting_periods', function (Blueprint $table): void {
                $table->foreign('closed_by_user_id')
                    ->references('id')
                    ->on('pas_users')
                    ->nullOnDelete();

                $table->foreign('reopened_by_user_id')
                    ->references('id')
                    ->on('pas_users')
                    ->nullOnDelete();
            });
        }

        Schema::table('pas_accounting_periods', function (Blueprint $table): void {
            $table->index(['school_id', 'fiscal_year'], 'pas_acct_periods_school_fy_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_accounting_periods')) {
            return;
        }

        Schema::table('pas_accounting_periods', function (Blueprint $table): void {
            $table->dropIndex('pas_acct_periods_school_fy_idx');
        });

        if (Schema::hasTable('pas_users')) {
            Schema::table('pas_accounting_periods', function (Blueprint $table): void {
                $table->dropForeign(['closed_by_user_id']);
                $table->dropForeign(['reopened_by_user_id']);
            });
        }

        Schema::table('pas_accounting_periods', function (Blueprint $table): void {
            $table->dropColumn(['name', 'fiscal_year', 'reopened_at', 'reopened_by_user_id']);
        });

        Schema::table('pas_accounting_periods', function (Blueprint $table): void {
            $table->renameColumn('closed_by_user_id', 'closed_by');
        });
    }
};
