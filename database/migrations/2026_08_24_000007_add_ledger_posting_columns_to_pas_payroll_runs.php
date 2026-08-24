<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 3 — connect a posted payroll run to the ledger.
 *
 * `rules/PLAN.md` §11 promised v1 would leave two things behind for the
 * accounting phase: a structured posting payload on the run, and a
 * `LedgerPostingService` seam. Neither was ever built (verified absent
 * 2026-08-24), so this migration and that service deliver them now rather
 * than build on them.
 *
 * `posting_payload` is the debit/credit breakdown the run produced, frozen
 * as JSON at the moment it posted. It duplicates what the journal entry
 * already holds, deliberately: the payload records what THIS run computed,
 * independent of anything that later happens to the entry. If the entry is
 * reversed, the payload still shows what was originally posted, which is
 * what makes a disagreement between payroll and the ledger diagnosable.
 *
 * `journal_entry_id` is the forward link. Nullable because runs posted
 * before this slice have no entry, and because a run may be posted while
 * its accounting period is closed — in which case the status still advances
 * and the ledger posting is refused, rather than blocking payroll on the
 * books being open.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_payroll_runs')) {
            return;
        }

        Schema::table('pas_payroll_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_payroll_runs', 'posting_payload')) {
                $table->json('posting_payload')->nullable();
            }
            if (! Schema::hasColumn('pas_payroll_runs', 'journal_entry_id')) {
                $table->unsignedBigInteger('journal_entry_id')->nullable();
            }
            if (! Schema::hasColumn('pas_payroll_runs', 'ledger_posted_at')) {
                $table->timestamp('ledger_posted_at')->nullable();
            }
        });

        if (Schema::hasTable('pas_journal_entries')) {
            Schema::table('pas_payroll_runs', function (Blueprint $table): void {
                // restrictOnDelete: a journal entry that a payroll run points
                // at is part of the ledger's history. Entries are never
                // deleted once posted anyway — they are reversed.
                $table->foreign('journal_entry_id')
                    ->references('id')
                    ->on('pas_journal_entries')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_payroll_runs')) {
            return;
        }

        if (Schema::hasTable('pas_journal_entries')) {
            Schema::table('pas_payroll_runs', function (Blueprint $table): void {
                $table->dropForeign(['journal_entry_id']);
            });
        }

        Schema::table('pas_payroll_runs', function (Blueprint $table): void {
            $table->dropColumn(['posting_payload', 'journal_entry_id', 'ledger_posted_at']);
        });
    }
};
