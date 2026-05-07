<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named pay periods admins can run payroll against. One row per cutoff window
 * (monthly or semi-monthly). The engine itself takes a `PayPeriodInput` value
 * object — this row hydrates into one of those — so the existing computation
 * service stays decoupled from this table per the Week 6 design.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_pay_periods')) {
            return;
        }

        Schema::create('pas_pay_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('frequency', 16);
            $table->date('start_date');
            $table->date('end_date');
            $table->date('cutoff_date')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamps();

            $table->index(['start_date', 'end_date'], 'pas_pay_periods_dates_idx');
            $table->index('status', 'pas_pay_periods_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_pay_periods');
    }
};
