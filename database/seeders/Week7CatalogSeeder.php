<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use Illuminate\Database\Seeder;

/**
 * Seeds the v1 catalog of custom deduction and allowance types that the
 * payroll computation engine consumes (Week 7).
 *
 * Idempotent: every row is created with `firstOrCreate` keyed on `code`,
 * so re-running the seeder never duplicates rows. Only the immutable
 * identity (`code` + `name`) is used as the lookup key — operational
 * fields (taxable, calc method, source, default amount) are passed in
 * the second array so re-seeding does NOT overwrite admin edits.
 *
 * The de-minimis cap is intentionally NULL: BIR caps are pending
 * (Q3 in rules/PLAN.md) and a super-admin will set them via the admin
 * UI once the client supplies the figures.
 */
final class Week7CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedDeductionTypes();
        $this->seedAllowances();
    }

    private function seedDeductionTypes(): void
    {
        DeductionType::query()->firstOrCreate(
            ['code' => 'sss_loan'],
            [
                'name' => 'SSS Salary Loan',
                'is_taxable' => false,
                'calc_method' => 'fixed',
                'source' => 'employee',
                'is_active' => true,
                'notes' => 'Employee-borne SSS salary loan amortization. Statutory loans are tracked here for payslip clarity but are NOT a statutory contribution.',
            ],
        );

        DeductionType::query()->firstOrCreate(
            ['code' => 'hmo'],
            [
                'name' => 'HMO Premium',
                'is_taxable' => false,
                'calc_method' => 'fixed',
                'source' => 'employee',
                'is_active' => true,
                'notes' => 'Employee share of the company-provided HMO plan premium.',
            ],
        );

        DeductionType::query()->firstOrCreate(
            ['code' => 'uniform'],
            [
                'name' => 'Uniform Deduction',
                'is_taxable' => true,
                'calc_method' => 'fixed',
                'source' => 'employee',
                'is_active' => true,
                'notes' => 'One-time / instalment uniform fee deducted from net pay.',
            ],
        );
    }

    private function seedAllowances(): void
    {
        Allowance::query()->firstOrCreate(
            ['code' => 'rice_subsidy'],
            [
                'name' => 'Rice Subsidy',
                'is_taxable' => false,
                'is_de_minimis' => true,
                'de_minimis_cap_centavos' => null, // BIR cap pending — Q3 in PLAN.md
                'default_amount_centavos' => 200_000, // PHP 2,000
                'is_active' => true,
                'notes' => 'De-minimis rice subsidy. Cap pending BIR confirmation.',
            ],
        );

        Allowance::query()->firstOrCreate(
            ['code' => 'transportation'],
            [
                'name' => 'Transportation Allowance',
                'is_taxable' => true,
                'is_de_minimis' => false,
                'de_minimis_cap_centavos' => null,
                'default_amount_centavos' => 300_000, // PHP 3,000
                'is_active' => true,
                'notes' => 'Taxable monthly transportation allowance.',
            ],
        );

        Allowance::query()->firstOrCreate(
            ['code' => 'meal'],
            [
                'name' => 'Meal Allowance',
                'is_taxable' => true,
                'is_de_minimis' => false,
                'de_minimis_cap_centavos' => null,
                'default_amount_centavos' => 200_000, // PHP 2,000
                'is_active' => true,
                'notes' => 'Taxable monthly meal allowance.',
            ],
        );

        Allowance::query()->firstOrCreate(
            ['code' => 'communication'],
            [
                'name' => 'Communication Allowance',
                'is_taxable' => true,
                'is_de_minimis' => false,
                'de_minimis_cap_centavos' => null,
                'default_amount_centavos' => 100_000, // PHP 1,000
                'is_active' => true,
                'notes' => 'Taxable monthly mobile / internet allowance.',
            ],
        );
    }
}
