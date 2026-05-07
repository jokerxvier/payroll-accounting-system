<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee subscription to a custom deduction type.
 *
 * Exactly one of `amount_centavos` or `percent_basis_points` is populated,
 * matched against the parent `pas_deduction_types.calc_method`:
 *   - calc_method = 'fixed'   → amount_centavos is required, percent_basis_points NULL
 *   - calc_method = 'percent' → percent_basis_points is required, amount_centavos NULL
 *
 * `percent_basis_points` is the percent expressed in BPs — 250 = 2.50%, 1_000 = 10.00%.
 * Storing as an integer keeps every percent calculation in pure integer
 * arithmetic; division happens at the end with banker's rounding.
 *
 * Effective-dated subscription: active when computation date falls in
 * `[effective_from, effective_to)`. Closing sets effective_to rather than
 * deleting so historical payslips stay reproducible.
 *
 * `schedule` controls which payroll runs the deduction applies to.
 *
 * FK to deduction type is RESTRICT (a catalog row in use cannot be
 * removed); FK to employee is CASCADE.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_employee_deductions')) {
            return;
        }

        Schema::create('pas_employee_deductions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_profile_id')
                ->constrained('pas_employee_profiles')
                ->cascadeOnDelete();

            $table->foreignId('deduction_type_id')
                ->constrained('pas_deduction_types')
                ->restrictOnDelete();

            $table->bigInteger('amount_centavos')->nullable();
            $table->integer('percent_basis_points')->nullable();

            $table->enum('schedule', [
                'every_run',
                'first_half',
                'second_half',
                'monthly_first',
                'monthly_last',
            ])->default('every_run');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(
                ['employee_profile_id', 'effective_from'],
                'pas_employee_deductions_profile_effective_idx'
            );
            $table->index('deduction_type_id', 'pas_employee_deductions_deduction_type_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_employee_deductions');
    }
};
