<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee subscription to an allowance type.
 *
 * Effective-dated: a subscription is active for periods whose computation
 * date falls in `[effective_from, effective_to)`. Closing a subscription
 * sets `effective_to` rather than deleting the row, so historical payslips
 * remain reproducible.
 *
 * `schedule` controls which payroll runs the allowance applies to (every
 * run, only the first half-month, only the second, only the first/last
 * monthly run). One-off allowances ride on `pas_payroll_adjustments`
 * instead of this table.
 *
 * Money is integer centavos. FK to allowance is RESTRICT (a catalog row in
 * use cannot be removed); FK to employee is CASCADE (deleting the profile
 * removes the subscription, but profiles themselves are soft-deleted in
 * practice).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_employee_allowances')) {
            return;
        }

        Schema::create('pas_employee_allowances', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_profile_id')
                ->constrained('pas_employee_profiles')
                ->cascadeOnDelete();

            $table->foreignId('allowance_id')
                ->constrained('pas_allowances')
                ->restrictOnDelete();

            $table->bigInteger('amount_centavos');
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
                'pas_employee_allowances_profile_effective_idx'
            );
            $table->index('allowance_id', 'pas_employee_allowances_allowance_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_employee_allowances');
    }
};
