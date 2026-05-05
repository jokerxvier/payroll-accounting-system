<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per employee per payroll run. Stores the full computed snapshot
 * (totals + audit lines + applied exemptions) so payroll history is immutable
 * even if rates, salaries, or exemptions later change.
 *
 * `lms_staff_id` is denormalised so payslip queries don't have to join
 * `pas_employee_profiles` to find the LMS staff identifier — handy for
 * reporting and for any future split where the LMS DB becomes physically
 * separate (CLAUDE.md: "ready for a future physical split").
 *
 * `audit_lines` is the canonical PayrollLineItem[] snapshot from the engine.
 * `applied_exemptions` is the `exempted_contribution_codes` value at compute
 * time, frozen for audit so a later change to the employee profile doesn't
 * silently rewrite history.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pas_payslips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')
                ->constrained('pas_payroll_runs')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('employee_profile_id')
                ->constrained('pas_employee_profiles')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->unsignedInteger('lms_staff_id');
            $table->bigInteger('gross_pay_centavos')->default(0);
            $table->bigInteger('total_employee_deductions_centavos')->default(0);
            $table->bigInteger('total_employer_contributions_centavos')->default(0);
            $table->bigInteger('net_pay_centavos')->default(0);
            $table->bigInteger('taxable_income_centavos')->default(0);
            $table->json('audit_lines');
            $table->json('applied_exemptions')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_profile_id'], 'pas_payslips_run_profile_uq');
            $table->index('payroll_run_id', 'pas_payslips_run_idx');
            $table->index('lms_staff_id', 'pas_payslips_lms_staff_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_payslips');
    }
};
