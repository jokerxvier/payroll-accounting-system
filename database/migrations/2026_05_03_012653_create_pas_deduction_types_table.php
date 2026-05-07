<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog of custom (non-statutory) deduction types — HMO premium, uniform,
 * SSS-loan-as-employer-deducted, salary deductions, etc.
 *
 * Statutory contributions (BIR/SSS/PhilHealth/Pag-IBIG) live on
 * `pas_statutory_contributions` and are NOT modelled here. This catalog
 * covers anything the company chooses to deduct outside the statutory set.
 *
 * Per-employee subscriptions live on `pas_employee_deductions`.
 *
 * `calc_method` distinguishes flat-amount vs percent-of-gross deductions.
 * `source` flags whether the cost is borne by employee, employer, or both.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_deduction_types')) {
            return;
        }

        Schema::create('pas_deduction_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->boolean('is_taxable')->default(true);
            $table->enum('calc_method', ['fixed', 'percent']);
            $table->enum('source', ['employee', 'employer', 'both'])->default('employee');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('is_active', 'pas_deduction_types_is_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_deduction_types');
    }
};
