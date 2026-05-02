<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\StatutoryContributionResolver;
use Illuminate\Database\Seeder;

/**
 * Seeds the v1 government statutory contribution rates that the payroll
 * computation engine consumes via {@see StatutoryContributionResolver}.
 *
 * One row per contribution code, each carrying the algorithm-specific `rules`
 * JSON payload. Idempotent: re-running re-uses any existing row keyed on
 * (contribution_code, effective_from).
 *
 * Source citations live alongside each private builder method — this seeder is
 * the audit trail for "where did these rates come from?".
 *
 * Rates seeded:
 *   - BIR Withholding Tax — TRAIN-law tables, effective 2023-01-01.
 *   - SSS Contribution     — RA 11199 / SSS Circular 2024-006, effective 2025-01-01.
 *   - PhilHealth Premium   — UHC Act / 5% rate, effective 2024-01-01.
 *   - Pag-IBIG Contribution — HDMF Circular 460, effective 2024-02-01.
 */
final class StatutoryContributionSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBirWithholdingTax();
        $this->seedSss();
        $this->seedPhilHealth();
        $this->seedPagIbig();
    }

    /**
     * Idempotent insert keyed on (contribution_code, effective_from).
     *
     * `firstOrCreate` is unsuitable here: the schema's `effective_from` is a
     * DATE column but Eloquent's default datetime serializer writes a full
     * `Y-m-d H:i:s` string, so a follow-up `where('effective_from', '2023-01-01')`
     * lookup fails to match the stored `'2023-01-01 00:00:00'` and inserts a
     * duplicate. Using `whereDate()` for the existence check sidesteps the
     * format mismatch on every supported driver (MySQL and the in-memory
     * SQLite used by tests).
     *
     * @param  array<string, mixed>  $attributes  Full row payload.
     */
    private function upsertContribution(string $contributionCode, string $effectiveFrom, array $attributes): void
    {
        $exists = StatutoryContribution::query()
            ->where('contribution_code', $contributionCode)
            ->whereDate('effective_from', $effectiveFrom)
            ->exists();

        if ($exists) {
            return;
        }

        StatutoryContribution::query()->create([
            'contribution_code' => $contributionCode,
            'effective_from' => $effectiveFrom,
            ...$attributes,
        ]);
    }

    /**
     * BIR withholding tax — TRAIN-law brackets, 2023 schedule onward.
     *
     * Source: BIR Revenue Regulation No. 8-2018, Annex A (Withholding Tax
     * Tables, 2023 schedule under the TRAIN law).
     *
     * Two parallel bracket tables are bundled in a single rules payload so the
     * BracketTableStrategy can dispatch by `context.period_type` (monthly or
     * semi_monthly). Amounts are stored as integer centavos and excess rates
     * as basis points (1500 = 15.00%).
     */
    private function seedBirWithholdingTax(): void
    {
        $this->upsertContribution(
            StatutoryContribution::CODE_BIR,
            '2023-01-01',
            [
                'label' => 'BIR Withholding Tax (TRAIN-law, 2023+)',
                'algorithm' => StatutoryContribution::ALGORITHM_BRACKET_TABLE,
                'effective_to' => null,
                'rules' => [
                    'period_types' => [
                        'monthly' => [
                            // < PHP 20,833 → 0% (exempt). Bracket boundaries
                            // are lower-inclusive, upper-exclusive, so a salary
                            // of exactly PHP 20,833.00 falls into the next
                            // bracket and starts paying 15%.
                            [
                                'lower' => 0,
                                'upper' => 2_083_300,
                                'base_tax' => 0,
                                'excess_rate_bp' => 0,
                                'excess_over' => 0,
                            ],
                            // PHP 20,833 - 33,332.99 → 15% over 20,833
                            [
                                'lower' => 2_083_300,
                                'upper' => 3_333_300,
                                'base_tax' => 0,
                                'excess_rate_bp' => 1_500,
                                'excess_over' => 2_083_300,
                            ],
                            // PHP 33,333 - 66,666.99 → 1,875 + 20% over 33,333
                            [
                                'lower' => 3_333_300,
                                'upper' => 6_666_700,
                                'base_tax' => 187_500,
                                'excess_rate_bp' => 2_000,
                                'excess_over' => 3_333_300,
                            ],
                            // PHP 66,667 - 166,666.99 → 8,541.80 + 25% over 66,667
                            [
                                'lower' => 6_666_700,
                                'upper' => 16_666_700,
                                'base_tax' => 854_180,
                                'excess_rate_bp' => 2_500,
                                'excess_over' => 6_666_700,
                            ],
                            // PHP 166,667 - 666,666.99 → 33,541.80 + 30% over 166,667
                            [
                                'lower' => 16_666_700,
                                'upper' => 66_666_700,
                                'base_tax' => 3_354_180,
                                'excess_rate_bp' => 3_000,
                                'excess_over' => 16_666_700,
                            ],
                            // >= PHP 666,667 → 183,541.80 + 35% over 666,667
                            [
                                'lower' => 66_666_700,
                                'upper' => null,
                                'base_tax' => 18_354_180,
                                'excess_rate_bp' => 3_500,
                                'excess_over' => 66_666_700,
                            ],
                        ],
                        'semi_monthly' => [
                            // < PHP 10,417 → 0% (exempt)
                            [
                                'lower' => 0,
                                'upper' => 1_041_700,
                                'base_tax' => 0,
                                'excess_rate_bp' => 0,
                                'excess_over' => 0,
                            ],
                            // PHP 10,417 - 16,666.99 → 15% over 10,417
                            [
                                'lower' => 1_041_700,
                                'upper' => 1_666_700,
                                'base_tax' => 0,
                                'excess_rate_bp' => 1_500,
                                'excess_over' => 1_041_700,
                            ],
                            // PHP 16,667 - 33,332.99 → 937.50 + 20% over 16,667
                            [
                                'lower' => 1_666_700,
                                'upper' => 3_333_300,
                                'base_tax' => 93_750,
                                'excess_rate_bp' => 2_000,
                                'excess_over' => 1_666_700,
                            ],
                            // PHP 33,333 - 83,332.99 → 4,270.70 + 25% over 33,333
                            [
                                'lower' => 3_333_300,
                                'upper' => 8_333_300,
                                'base_tax' => 427_070,
                                'excess_rate_bp' => 2_500,
                                'excess_over' => 3_333_300,
                            ],
                            // PHP 83,333 - 333,332.99 → 16,770.70 + 30% over 83,333
                            [
                                'lower' => 8_333_300,
                                'upper' => 33_333_300,
                                'base_tax' => 1_677_070,
                                'excess_rate_bp' => 3_000,
                                'excess_over' => 8_333_300,
                            ],
                            // >= PHP 333,333 → 91,770.70 + 35% over 333,333
                            [
                                'lower' => 33_333_300,
                                'upper' => null,
                                'base_tax' => 9_177_070,
                                'excess_rate_bp' => 3_500,
                                'excess_over' => 33_333_300,
                            ],
                        ],
                    ],
                ],
                'notes' => 'BIR Revenue Regulation No. 8-2018, Annex A - Withholding Tax Tables (2023 schedule under the TRAIN law). Reference: https://www.bir.gov.ph/index.php/tax-information/withholding-tax.html',
            ],
        );
    }

    /**
     * SSS contribution table — January 2025 rate (14% total: 5% EE / 9% ER).
     *
     * Source: SSS Circular 2024-006 (RA 11199 - Social Security Act of 2018).
     * The published table covers MSC ₱5,000 through MSC ₱35,000 in ₱500
     * increments — 61 bands generated programmatically below.
     *
     * EC premium: ₱10 when MSC ≤ ₱14,500, ₱30 above. Stored as
     * `employer_aux_share` per the SalaryBandStrategy contract.
     */
    private function seedSss(): void
    {
        $this->upsertContribution(
            StatutoryContribution::CODE_SSS,
            '2025-01-01',
            [
                'label' => 'SSS Contribution (2025)',
                'algorithm' => StatutoryContribution::ALGORITHM_SALARY_BAND,
                'effective_to' => null,
                'rules' => [
                    'bands' => $this->buildSssBands(),
                ],
                'notes' => 'SSS Circular 2024-006 (RA 11199); 14% rate (5% EE / 9% ER) effective January 2025. EC premium: PHP 10 for MSC <= PHP 14,500, PHP 30 above.',
            ],
        );
    }

    /**
     * Generate the 61 SSS salary bands per the January 2025 contribution table.
     *
     * The Monthly Salary Credit (MSC) starts at ₱5,000 and increments by ₱500
     * up to ₱35,000. Each band's Monthly Salary Range (MSR) covers
     * `MSC - 250` through `MSC + 249.99`. The first band's lower bound is 0
     * (so very low salaries still hit MSC ₱5,000) and the last band's upper
     * bound is null (so very high salaries are capped at MSC ₱35,000).
     *
     * Shares are computed as `MSC × rate_bp / 10000`, rounded to centavos
     * with PHP's default round-half-away-from-zero. The downstream engine
     * uses banker's rounding for live computations; these seeded shares
     * match the official SSS table values.
     *
     * @return list<array{lower:int,upper:?int,contribution_base:int,employee_share:int,employer_share:int,employer_aux_share:int,employee_aux_share:int}>
     */
    private function buildSssBands(): array
    {
        $employeeRateBp = 500;  // 5.00%
        $employerRateBp = 900;  // 9.00%

        $minMsc = 500_000;       // PHP 5,000 in centavos
        $step = 50_000;          // PHP 500
        $maxMsc = 3_500_000;     // PHP 35,000
        $ecLowerThreshold = 1_450_000; // PHP 14,500 — at-or-below pays PHP 10 EC

        $bands = [];

        for ($msc = $minMsc; $msc <= $maxMsc; $msc += $step) {
            $lower = $msc === $minMsc ? 0 : $msc - 25_000;
            $upper = $msc === $maxMsc ? null : $msc + 25_000;

            $employeeShare = (int) round($msc * $employeeRateBp / 10_000);
            $employerShare = (int) round($msc * $employerRateBp / 10_000);
            $ecShare = $msc <= $ecLowerThreshold ? 1_000 : 3_000;

            $bands[] = [
                'lower' => $lower,
                'upper' => $upper,
                'contribution_base' => $msc,
                'employee_share' => $employeeShare,
                'employer_share' => $employerShare,
                'employer_aux_share' => $ecShare,
                'employee_aux_share' => 0,
            ];
        }

        return $bands;
    }

    /**
     * PhilHealth premium — 5% rate, ₱10,000 floor and ₱100,000 ceiling.
     *
     * Source: PhilHealth Circular per the Universal Health Care Act
     * (RA 11223). The 5% rate has been stable from 2024 onward; the prior
     * 4.5% rate ended on 2023-12-31.
     */
    private function seedPhilHealth(): void
    {
        $this->upsertContribution(
            StatutoryContribution::CODE_PHILHEALTH,
            '2024-01-01',
            [
                'label' => 'PhilHealth Premium (5%, 2024+)',
                'algorithm' => StatutoryContribution::ALGORITHM_PERCENTAGE_WITH_CAP,
                'effective_to' => null,
                'rules' => [
                    'rate_bp' => 500,            // 5.00%
                    'floor' => 1_000_000,        // PHP 10,000
                    'ceiling' => 10_000_000,     // PHP 100,000
                    'split' => [
                        'employee_bp' => 5_000,  // 50.00%
                        'employer_bp' => 5_000,  // 50.00%
                    ],
                ],
                'notes' => 'PhilHealth Circular per the Universal Health Care Act (RA 11223); 5% rate stable from 2024 onward.',
            ],
        );
    }

    /**
     * Pag-IBIG contribution — HDMF Circular 460, effective February 2024.
     *
     * Source: HDMF Circular 460. Threshold ₱1,500 monthly compensation:
     *   - At or below: 1% employee / 2% employer.
     *   - Above:       2% employee / 2% employer.
     * Monthly Compensation cap (max MSC) is ₱10,000, raised from the prior
     * ₱5,000 ceiling under Circular 460.
     */
    private function seedPagIbig(): void
    {
        $this->upsertContribution(
            StatutoryContribution::CODE_PAGIBIG,
            '2024-02-01',
            [
                'label' => 'Pag-IBIG Contribution (HDMF Circular 460, 2024+)',
                'algorithm' => StatutoryContribution::ALGORITHM_TIERED_PERCENTAGE,
                'effective_to' => null,
                'rules' => [
                    'threshold' => 150_000,                            // PHP 1,500
                    'lower' => ['ee_bp' => 100, 'er_bp' => 200],       // 1% / 2%
                    'upper' => ['ee_bp' => 200, 'er_bp' => 200],       // 2% / 2%
                    'max_msc' => 1_000_000,                            // PHP 10,000 cap
                ],
                'notes' => 'HDMF Circular 460 effective Feb 2024. Threshold PHP 1,500: at-or-below 1%/2%, above 2%/2%. Monthly Compensation cap raised to PHP 10,000.',
            ],
        );
    }
}
