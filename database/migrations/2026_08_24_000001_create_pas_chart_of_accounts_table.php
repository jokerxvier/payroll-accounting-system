<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 1 — the chart of accounts, root of the double-entry ledger.
 *
 * One row per account. Every journal entry line points at one of these, so
 * this table is the vocabulary the whole accounting module speaks.
 *
 * Tenant-scoped from birth: unlike the Phase-B catalog conversion (which had
 * to add `school_id` to live tables in stages), this table is new, so
 * `school_id` is NOT NULL immediately and `code` is unique per school. Each
 * school maintains its own chart; SchoolObserver clones the default school's
 * chart onto every new school.
 *
 * Two columns exist purely to keep the financial reports correct and must
 * not be dropped as redundant:
 *
 *   `normal_balance` — whether the account increases on the debit or the
 *     credit side. Derivable from `type` (asset/expense = debit, everything
 *     else = credit), but stored explicitly because the General Ledger
 *     ending-balance formula depends on it:
 *         debit-normal:  ending = opening + debits - credits
 *         credit-normal: ending = opening + credits - debits
 *     The client's requirements doc gives only the first form, which would
 *     sign-flip every liability, equity, and income balance.
 *
 *   `cash_flow_category` — operating / investing / financing classification
 *     backing the Cash Flow Statement. There is no way to infer it from
 *     `type` (both interest expense and salaries are expenses, but only one
 *     is operating), so it has to be captured per account at setup time.
 *
 * `system_code` marks accounts the software itself posts to (AR control, VAT
 * output, payroll clearing, ...). Rows carrying one are flagged `is_locked`
 * and may not be deleted or re-coded through the UI — losing them would
 * break posting. Nullable, and unique per school when present.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_chart_of_accounts')) {
            return;
        }

        Schema::create('pas_chart_of_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            $table->string('code', 20);
            $table->string('name', 160);
            $table->string('type', 16);
            $table->string('subtype', 40)->nullable();
            $table->string('normal_balance', 8);
            $table->string('cash_flow_category', 16)->default('none');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('system_code', 40)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['school_id', 'code'], 'pas_coa_school_code_unq');
            $table->unique(['school_id', 'system_code'], 'pas_coa_school_system_code_unq');
            $table->index(['school_id', 'type'], 'pas_coa_school_type_idx');
            $table->index(['school_id', 'is_active'], 'pas_coa_school_active_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            // Self-FK for the account hierarchy (e.g. 1100 Cash → 1101 Cash
            // on Hand). restrictOnDelete so a parent with children cannot be
            // removed out from under them.
            $table->foreign('parent_id')
                ->references('id')
                ->on('pas_chart_of_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_chart_of_accounts');
    }
};
