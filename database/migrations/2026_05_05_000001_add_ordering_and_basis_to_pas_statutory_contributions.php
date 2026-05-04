<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an explicit application order and basis ("applies_to") column to the
 * statutory contribution rule set. This lets the payroll engine sequence
 * deductions deterministically (SSS → PhilHealth → Pag-IBIG → BIR) and pin
 * each contribution to the correct base amount (gross pay vs taxable income).
 *
 * Backfill targets the four PH rows seeded for v1:
 *   - SSS              → order 10, applies_to gross_pay
 *   - PHILHEALTH       → order 20, applies_to gross_pay
 *   - PAGIBIG          → order 30, applies_to gross_pay
 *   - BIR_WITHHOLDING  → order 90, applies_to taxable_income
 *
 * The defaults (100 / 'gross_pay') intentionally place any future,
 * unconfigured contribution after the four seeded ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pas_statutory_contributions', function (Blueprint $table) {
            $table->integer('application_order')
                ->default(100)
                ->after('algorithm');

            $table->string('applies_to', 32)
                ->default('gross_pay')
                ->after('application_order');

            $table->index('application_order', 'pas_statutory_contributions_app_order_idx');
        });

        $backfill = [
            ['code' => 'SSS', 'order' => 10, 'applies_to' => 'gross_pay'],
            ['code' => 'PHILHEALTH', 'order' => 20, 'applies_to' => 'gross_pay'],
            ['code' => 'PAGIBIG', 'order' => 30, 'applies_to' => 'gross_pay'],
            ['code' => 'BIR_WITHHOLDING', 'order' => 90, 'applies_to' => 'taxable_income'],
        ];

        foreach ($backfill as $row) {
            DB::table('pas_statutory_contributions')
                ->where('contribution_code', $row['code'])
                ->update([
                    'application_order' => $row['order'],
                    'applies_to' => $row['applies_to'],
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('pas_statutory_contributions', function (Blueprint $table) {
            $table->dropIndex('pas_statutory_contributions_app_order_idx');
            $table->dropColumn(['application_order', 'applies_to']);
        });
    }
};
