<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-off adjustments — a bonus, a single deduction, a back-pay correction.
 *
 * Recurring allowances + deductions live on their own tables (per-employee
 * subscription tables); this table is for ad-hoc lines that fire on a single
 * payroll period and never repeat.
 *
 * `kind` partitions additions vs deductions; `is_taxable` independently
 * controls whether the line folds into the BIR taxable base.
 *
 * Period-matching by date (decision 1 in the Week 7 plan):
 *   - `applies_on` is the calendar date the adjustment applies to.
 *   - `pas_pay_periods` does not exist until Week 9, so the compute path
 *     resolves an adjustment to a period via
 *     `period.start() <= applies_on <= period.end()`.
 *   - `applied_pay_period_id` is reserved as a future cache column populated
 *     by Week 9's backfill. It is intentionally an unsigned bigint with no
 *     FK constraint today — the FK and table arrive together in Week 9.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_payroll_adjustments')) {
            return;
        }

        Schema::create('pas_payroll_adjustments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_profile_id')
                ->constrained('pas_employee_profiles')
                ->cascadeOnDelete();

            $table->enum('kind', ['addition', 'deduction']);
            $table->boolean('is_taxable')->default(true);
            $table->bigInteger('amount_centavos');
            $table->date('applies_on');
            $table->string('label', 120);
            $table->text('reason')->nullable();

            // Reserved for Week-9 cache backfill; no FK constraint yet because
            // pas_pay_periods does not exist until Week 9.
            $table->unsignedBigInteger('applied_pay_period_id')->nullable();

            $table->timestamps();

            $table->index(
                ['employee_profile_id', 'applies_on'],
                'pas_payroll_adjustments_profile_applies_on_idx'
            );
            $table->index('applied_pay_period_id', 'pas_payroll_adjustments_pay_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_payroll_adjustments');
    }
};
