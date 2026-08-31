<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standing instruction to raise the same invoice on a cadence.
 *
 * The template lines live in `pas_recurring_invoice_lines`; the record of which
 * periods have already been billed lives in `pas_recurring_invoice_periods`.
 * Three tables rather than one because the three have different lifetimes — a
 * schedule is edited, its lines are replaced wholesale, and a claim must
 * outlive the invoice it produced.
 *
 * Every index is named explicitly. Laravel's generated names run past MySQL's
 * 64-character identifier limit on a table name this long, and SQLite accepts
 * them happily — so an unnamed index fails `migrate` in production while the
 * whole test suite stays green.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_recurring_invoices')) {
            return;
        }

        Schema::create('pas_recurring_invoices', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('school_id');

            // What the operator calls it in the list: "Grade 7 tuition — Dela Cruz".
            $table->string('name', 120);

            // Bills recur too — a monthly retainer, a photocopier lease. The
            // invoice table already serves both, so hard-coding this to sales
            // would only mean a migration later.
            $table->string('type', 16)->default('sales');

            $table->unsignedBigInteger('contact_id');

            // No FK: student ids live on the `lms` connection and repeat across
            // tenant databases, so every lookup is composite with school_id.
            $table->unsignedBigInteger('lms_student_id')->nullable();
            $table->string('student_name', 160)->nullable();

            // Copied onto each generated invoice.
            $table->string('reference', 64)->nullable();
            $table->boolean('is_vat_inclusive')->default(false);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->string('frequency', 16)->default('monthly');

            // Stored intent, 1–31, clamped to the month at use. Kept as given
            // so a schedule set to the 31st still bills the 31st in March
            // after February clamped it to the 28th.
            $table->unsignedTinyInteger('day_of_month')->default(1);

            $table->date('starts_on');
            // Null runs until someone pauses it.
            $table->date('ends_on')->nullable();

            // The cursor, and the only column the generator advances.
            $table->date('next_run_on');

            // due_date = issue_date + due_days. Null leaves the invoice with
            // no due date, which is legal.
            $table->unsignedSmallInteger('due_days')->nullable();

            $table->boolean('is_active')->default(true);

            $table->date('last_generated_on')->nullable();
            $table->unsignedInteger('generated_count')->default(0);

            // Why last night's run skipped this schedule, in a sentence an
            // operator can act on. Excluded from the audit payload — a
            // permanently broken schedule would otherwise write an audit row
            // every night forever into a table auditors export.
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_error_at')->nullable();

            $table->timestamps();

            $table->foreign('school_id')
                ->references('id')->on('pas_schools')
                ->restrictOnDelete();

            $table->foreign('contact_id')
                ->references('id')->on('pas_contacts')
                ->restrictOnDelete();

            $table->index(['school_id', 'is_active'], 'pas_recinv_school_active_idx');
            // The generator's only hot query: what is due today.
            $table->index(['school_id', 'is_active', 'next_run_on'], 'pas_recinv_due_idx');
            $table->index(['school_id', 'contact_id'], 'pas_recinv_school_contact_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_recurring_invoices');
    }
};
