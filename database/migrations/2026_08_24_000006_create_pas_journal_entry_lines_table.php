<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 2 — the debit and credit lines of a journal entry.
 *
 * Both amounts are integer centavos, and exactly one of them is non-zero on
 * any given line. That is a domain rule rather than a database one: MySQL
 * check constraints are not portable to the sqlite the test suite runs on,
 * so it is asserted in `PostJournalEntry` and covered by tests. Keeping two
 * unsigned columns rather than one signed amount matches how a ledger is
 * read and printed (`THEME.md` §6.3) and removes any question about which
 * sign means what.
 *
 * `account_id` is `restrictOnDelete`: an account with posted lines against it
 * is part of the ledger's history and must not be removable. The chart-of-
 * accounts controller already soft-blocks deletion for sub-accounts and tax
 * rates; this makes the ledger itself the final backstop.
 *
 * `journal_entry_id` is `cascadeOnDelete` so the database can never be left
 * with orphaned lines. JournalEntryObserver deletes the lines through
 * Eloquent first so each one still produces an audit row — the same trap
 * that let payroll-run deletes destroy payslips silently.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_journal_entry_lines')) {
            return;
        }

        Schema::create('pas_journal_entry_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedInteger('line_number')->default(1);
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('debit_centavos')->default(0);
            $table->bigInteger('credit_centavos')->default(0);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['journal_entry_id', 'line_number'], 'pas_jel_entry_line_idx');
            // The General Ledger and Trial Balance both walk lines by
            // account; this is the index they lean on.
            $table->index(['school_id', 'account_id'], 'pas_jel_school_account_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            $table->foreign('journal_entry_id')
                ->references('id')
                ->on('pas_journal_entries')
                ->cascadeOnDelete();

            $table->foreign('account_id')
                ->references('id')
                ->on('pas_chart_of_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_journal_entry_lines');
    }
};
