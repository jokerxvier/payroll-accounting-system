<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "Generate payroll" execution against a pay period. The status
 * machine lives here:
 *   draft → computing → computed → pending_approval → approved → posted
 *                                                       ↘ voided
 *
 * Totals are denormalised so the run-detail page can render headline figures
 * without aggregating thousands of payslip rows on every reload.
 *
 * actor FKs (`approved_by_user_id`, `voided_by_user_id`) reference the LMS
 * `users.id` column, which is `int unsigned` in the legacy schema (NOT bigint).
 * `foreignId()` defaults to bigint and would create a type mismatch, so we
 * declare these as `unsignedInteger` and add the FK constraint manually.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_payroll_runs')) {
            return;
        }

        Schema::create('pas_payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pay_period_id')
                ->constrained('pas_pay_periods')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('total_employees')->default(0);
            $table->bigInteger('total_employee_deductions_centavos')->default(0);
            $table->bigInteger('total_employer_contributions_centavos')->default(0);
            $table->bigInteger('total_net_pay_centavos')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('approved_by_user_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedInteger('voided_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['pay_period_id', 'status'], 'pas_payroll_runs_period_status_idx');
            $table->index('status', 'pas_payroll_runs_status_idx');
            $table->index('voided_at', 'pas_payroll_runs_voided_at_idx');

            $table->foreign('approved_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('voided_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_payroll_runs');
    }
};
