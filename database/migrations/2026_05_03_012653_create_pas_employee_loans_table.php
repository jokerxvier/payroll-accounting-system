<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight employee loan ledger header.
 *
 * No interest schedule and no per-period amortization rows — Phase 2 only
 * tracks the running outstanding balance. Adding the next-amortization-due
 * field, schedules, or interest is a v2 concern (see rules/PLAN.md:192).
 *
 * `outstanding_balance_centavos` is denormalized: the compute path reads
 * it to clamp the next deduction against the remaining balance (so an
 * approaching-zero loan never over-deducts), and the Week-9 batch path
 * mutates it inside a transaction with `SELECT ... FOR UPDATE`. The
 * `lock_version` column gives the persistence path an optimistic-lock
 * fallback in addition to the row lock.
 *
 * `code` is a free-text loan reference (e.g. "SSS-2024-001", "HRDept-Loan-7")
 * — uniqueness is per-employee not global, enforced at the application layer.
 *
 * `closed_on` is set when the balance hits zero; open loans (`closed_on IS
 * NULL`) are picked up by `EmployeeLoan::scopeOpen` at compute time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pas_employee_loans', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_profile_id')
                ->constrained('pas_employee_profiles')
                ->cascadeOnDelete();

            $table->string('code', 64);
            $table->bigInteger('principal_centavos');
            $table->bigInteger('outstanding_balance_centavos');
            $table->bigInteger('monthly_amortization_centavos');

            $table->enum('schedule', [
                'every_run',
                'first_half',
                'second_half',
                'monthly_first',
                'monthly_last',
            ])->default('every_run');

            $table->date('started_on');
            $table->date('closed_on')->nullable();
            $table->integer('lock_version')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(
                ['employee_profile_id', 'closed_on'],
                'pas_employee_loans_profile_closed_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_employee_loans');
    }
};
