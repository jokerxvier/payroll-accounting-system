<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 2 — the journal. One row per accounting transaction; the
 * debit and credit lines live on `pas_journal_entry_lines`.
 *
 * Status machine (enforced in the action layer):
 *   draft → pending → posted
 *                 ↘ voided
 *
 * A posted entry is immutable. Correcting one means posting a reversing
 * entry that points back at it through `reversal_of_entry_id`, per
 * `rules/CODING_STANDARDS_LARAVEL.md` §471 — the original is never mutated
 * and never deleted. Critically it also stays `posted`: the pair offsets to
 * zero, so every report that reads posted entries sees the correction
 * happen rather than seeing the original disappear. `voided` is reserved for
 * abandoning an entry that never posted.
 *
 * `accounting_period_id` is resolved from `date` at post time and pinned on
 * the row. Pinning it rather than re-deriving on read means closing a period
 * freezes exactly the entries that were filed into it, even if someone later
 * edits the period's boundaries.
 *
 * `total_debit_centavos` / `total_credit_centavos` are denormalised so the
 * journal list can show entry totals without aggregating lines on every
 * render — the same reasoning as the totals on `pas_payroll_runs`. They are
 * written by the posting action and by nothing else, and the action asserts
 * they are equal before it will persist a posted entry.
 *
 * `source_type` / `source_id` record what produced the entry — a payroll
 * run in Slice 3, an invoice or payment in Slices 5-7 — so a posted figure
 * can always be traced back to the document that caused it. Null for a
 * manually keyed entry.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_journal_entries')) {
            return;
        }

        Schema::create('pas_journal_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            // Nullable: a draft has no number. Numbers are allocated by
            // PostJournalEntry at post time so a draft that is abandoned
            // never burns one. Both MySQL and sqlite treat NULLs as distinct
            // in a unique index, so any number of unnumbered drafts coexist.
            $table->string('entry_number', 32)->nullable();
            $table->unsignedBigInteger('accounting_period_id')->nullable();
            $table->date('date');
            $table->string('reference', 64)->nullable();
            $table->text('narration')->nullable();
            $table->string('status', 16)->default('draft');

            $table->string('source_type', 160)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->bigInteger('total_debit_centavos')->default(0);
            $table->bigInteger('total_credit_centavos')->default(0);

            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            // `reversed_*`, not `voided_*`: a posted entry is never voided.
            // It is offset by a reversing entry, and BOTH stay posted so the
            // two cancel out in every report. Marking the original voided
            // would drop it from `scopePosted()` and leave only the
            // reversal — understating the account by the full amount.
            // These stamps record who reversed it and when; the figures and
            // lines are untouched.
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_user_id')->nullable();
            $table->unsignedBigInteger('reversal_of_entry_id')->nullable();

            $table->timestamps();

            $table->unique(['school_id', 'entry_number'], 'pas_je_school_number_unq');
            $table->index(['school_id', 'status'], 'pas_je_school_status_idx');
            $table->index(['school_id', 'date'], 'pas_je_school_date_idx');
            $table->index('accounting_period_id', 'pas_je_period_idx');
            // Trace a posted figure back to the document that caused it.
            $table->index(['source_type', 'source_id'], 'pas_je_source_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            // restrictOnDelete: a period that has entries filed against it is
            // the ledger's own filing system and must not vanish underneath
            // them. AccountingPeriodPolicy already refuses deletion outright.
            $table->foreign('accounting_period_id')
                ->references('id')
                ->on('pas_accounting_periods')
                ->restrictOnDelete();

            $table->foreign('reversal_of_entry_id')
                ->references('id')
                ->on('pas_journal_entries')
                ->restrictOnDelete();
        });

        if (Schema::hasTable('pas_users')) {
            Schema::table('pas_journal_entries', function (Blueprint $table): void {
                $table->foreign('posted_by_user_id')
                    ->references('id')
                    ->on('pas_users')
                    ->nullOnDelete();

                $table->foreign('reversed_by_user_id')
                    ->references('id')
                    ->on('pas_users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_journal_entries');
    }
};
