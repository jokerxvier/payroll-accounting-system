<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use Illuminate\Database\Seeder;

/**
 * Demo-realistic payroll catalog for client UAT.
 *
 * Pairs with {@see DemoUsersSeeder} so a fresh demo environment shows a fully
 * populated catalog the moment someone signs in. Composes the two existing
 * seeders that already produce demo-grade data, then extends both catalog
 * tables with additional rows that are common on a Philippine payroll but not
 * carried by the v1 baseline:
 *
 *   - {@see StatutoryContributionSeeder} — BIR / SSS / PhilHealth / Pag-IBIG.
 *     Kept as-is. Numbers are sourced (RA 11199, RA 11223, HDMF Circular 460,
 *     BIR RR 8-2018) and re-deriving them in this seeder would be duplicative.
 *   - {@see Week7CatalogSeeder} — baseline 3 deduction types and 4 allowances.
 *     Kept as-is. This seeder layers six additional deduction types and five
 *     additional allowances on top.
 *
 * Idempotent — `firstOrCreate` keyed on `code` for catalog rows. Only the
 * lookup key is in the first array; operational fields (taxable / calc /
 * source / notes) live in the second array, so re-running this seeder never
 * overwrites admin edits made through the catalog UI.
 *
 * NOT registered in DatabaseSeeder::run(). Demo data is opt-in:
 *
 *     php artisan db:seed --class=DemoCatalogSeeder
 *
 * The de-minimis cap column is intentionally NULL on every row. The BIR caps
 * are pending (Q3 in rules/PLAN.md) and a super-admin will set them via the
 * admin UI once the client supplies the figures. This matches the convention
 * already in {@see Week7CatalogSeeder}.
 */
final class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Statutory contributions — current 2024/2025 rates with full source
        // citations. The upstream seeder is idempotent (whereDate existence
        // check on contribution_code + effective_from).
        $this->call(StatutoryContributionSeeder::class);

        // Baseline catalog — 3 deduction types + 4 allowances. Idempotent
        // (firstOrCreate keyed on `code`).
        $this->call(Week7CatalogSeeder::class);

        // Demo-only extensions on top of the baseline.
        $this->seedExtraDeductionTypes();
        $this->seedExtraAllowances();
    }

    /**
     * Six additional non-statutory deduction types covering the common PH
     * payroll cases beyond Week7's salary-loan / HMO / uniform baseline:
     * multipurpose loan, cash advance, cooperative dues, tardiness, absence,
     * Pag-IBIG MPL/HSL.
     *
     * Tardiness and absence are flagged taxable because they reduce the
     * taxable basis (they are not statutory deductions — they shrink gross
     * pay before the BIR base is computed). Statutory loans (SSS, Pag-IBIG)
     * and HMO premiums are not themselves taxable.
     */
    private function seedExtraDeductionTypes(): void
    {
        DeductionType::query()->firstOrCreate(
            ['code' => 'multipurpose_loan'],
            [
                'name' => 'Multipurpose / Salary Loan',
                'is_taxable' => false,
                'calc_method' => DeductionType::CALC_FIXED,
                'source' => DeductionType::SOURCE_EMPLOYEE,
                'is_active' => true,
                'notes' => 'Company-issued multipurpose / salary loan amortization. Tracked here for payslip clarity.',
            ],
        );

        DeductionType::query()->firstOrCreate(
            ['code' => 'cash_advance'],
            [
                'name' => 'Cash Advance',
                'is_taxable' => false,
                'calc_method' => DeductionType::CALC_FIXED,
                'source' => DeductionType::SOURCE_EMPLOYEE,
                'is_active' => true,
                'notes' => 'One-off or instalment cash advance recovery against the employee.',
            ],
        );

        DeductionType::query()->firstOrCreate(
            ['code' => 'cooperative_dues'],
            [
                'name' => 'Cooperative Dues',
                'is_taxable' => false,
                'calc_method' => DeductionType::CALC_FIXED,
                'source' => DeductionType::SOURCE_EMPLOYEE,
                'is_active' => true,
                'notes' => 'Employee cooperative monthly dues / share contribution.',
            ],
        );

        DeductionType::query()->firstOrCreate(
            ['code' => 'tardiness'],
            [
                'name' => 'Tardiness Deduction',
                'is_taxable' => true,
                'calc_method' => DeductionType::CALC_FIXED,
                'source' => DeductionType::SOURCE_EMPLOYEE,
                'is_active' => true,
                'notes' => 'Late / undertime deduction. Posted per period; reduces taxable basis.',
            ],
        );

        DeductionType::query()->firstOrCreate(
            ['code' => 'absences'],
            [
                'name' => 'Absence Deduction',
                'is_taxable' => true,
                'calc_method' => DeductionType::CALC_FIXED,
                'source' => DeductionType::SOURCE_EMPLOYEE,
                'is_active' => true,
                'notes' => 'Unpaid-day deduction. Posted per period; reduces taxable basis.',
            ],
        );

        DeductionType::query()->firstOrCreate(
            ['code' => 'pagibig_loan'],
            [
                'name' => 'Pag-IBIG MPL / HSL Loan',
                'is_taxable' => false,
                'calc_method' => DeductionType::CALC_FIXED,
                'source' => DeductionType::SOURCE_EMPLOYEE,
                'is_active' => true,
                'notes' => 'Employee-borne HDMF Multi-Purpose / Housing Loan amortization.',
            ],
        );
    }

    /**
     * Five additional allowance types covering common PH lines beyond Week7's
     * rice / transportation / meal / communication baseline: clothing,
     * laundry, medical-cash, COLA, 13th-month adjustment.
     *
     * The de_minimis_cap_centavos column stays NULL on every row — BIR caps
     * are confirmed by super-admin via the admin UI once the client supplies
     * them (Q3 in rules/PLAN.md). The 13th-month BIR PHP 90,000 non-taxable
     * cap is enforced at computation time, not at the catalog level — the
     * allowance row is marked is_taxable=false to align with how the
     * Allowances breakdown sums it on the gross side.
     */
    private function seedExtraAllowances(): void
    {
        Allowance::query()->firstOrCreate(
            ['code' => 'clothing'],
            [
                'name' => 'Clothing / Uniform Allowance',
                'is_taxable' => false,
                'is_de_minimis' => true,
                'de_minimis_cap_centavos' => null,
                'default_amount_centavos' => 50_000, // PHP 500
                'is_active' => true,
                'notes' => 'De-minimis clothing / uniform allowance. BIR cap pending — Q3 in PLAN.md.',
            ],
        );

        Allowance::query()->firstOrCreate(
            ['code' => 'laundry'],
            [
                'name' => 'Laundry Allowance',
                'is_taxable' => false,
                'is_de_minimis' => true,
                'de_minimis_cap_centavos' => null,
                'default_amount_centavos' => 30_000, // PHP 300
                'is_active' => true,
                'notes' => 'De-minimis laundry allowance.',
            ],
        );

        Allowance::query()->firstOrCreate(
            ['code' => 'medical_cash'],
            [
                'name' => 'Medical Cash Allowance',
                'is_taxable' => false,
                'is_de_minimis' => true,
                'de_minimis_cap_centavos' => null,
                'default_amount_centavos' => 150_000, // PHP 1,500
                'is_active' => true,
                'notes' => 'De-minimis medical cash allowance to dependents.',
            ],
        );

        Allowance::query()->firstOrCreate(
            ['code' => 'cola'],
            [
                'name' => 'Cost of Living Allowance',
                'is_taxable' => true,
                'is_de_minimis' => false,
                'de_minimis_cap_centavos' => null,
                'default_amount_centavos' => 200_000, // PHP 2,000
                'is_active' => true,
                'notes' => 'Taxable monthly cost-of-living allowance.',
            ],
        );

        Allowance::query()->firstOrCreate(
            ['code' => 'thirteenth_month_adj'],
            [
                'name' => '13th Month Adjustment',
                'is_taxable' => false,
                'is_de_minimis' => false,
                'de_minimis_cap_centavos' => null,
                'default_amount_centavos' => null,
                'is_active' => true,
                'notes' => 'Per-period 13th-month accrual / adjustment. Non-taxable up to BIR PHP 90,000 cap; cap is enforced at computation time, not the catalog level.',
            ],
        );
    }
}
