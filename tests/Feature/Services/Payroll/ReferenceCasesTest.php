<?php

declare(strict_types=1);

use App\Models\Pas\EmployeeProfile;
use App\Services\Payroll\PayrollComputationService;
use App\ValueObjects\PayPeriodInput;
use Database\Seeders\StatutoryContributionSeeder;

/*
 * REPLACE WITH CLIENT REFERENCE CASES on receipt (per rules/PLAN.md:345).
 *
 * Until the client provides hand-verified payroll cases, these 10 cases are
 * derived from the rates seeded in database/seeders/StatutoryContributionSeeder.php.
 * They prove the engine works against THOSE specific rates; client-supplied
 * cases will catch any drift between our seeded rates and the client's
 * actual table.
 *
 * Every expected value below was hand-computed from the source regulations
 * (BIR Revenue Regulation No. 8-2018, SSS Circular 2024-006, UHC Act,
 * HDMF Circular 460). Each case includes a comment block tracing the math
 * so a reviewer can verify without re-deriving.
 *
 * Banker's-rounding notes (see App\ValueObjects\Money::dividedBy()):
 *   - intdiv truncates toward zero; the remainder is then compared.
 *   - 2 × |remainder| < |divisor|  → round toward zero (truncation wins).
 *   - 2 × |remainder| > |divisor|  → round away from zero.
 *   - 2 × |remainder| = |divisor|  → round to even.
 * The seeded SSS shares use PHP's `round()` (half-away-from-zero) at seed
 * time per the official table; live computations through the engine use
 * banker's rounding via Money::dividedBy() for tax math.
 *
 * RefreshDatabase is inherited from tests/Pest.php for everything under
 * tests/Feature, so each `it` block runs against a fresh in-memory schema.
 */

beforeEach(function (): void {
    $this->seed(StatutoryContributionSeeder::class);
});

/**
 * Case 1: Minimum wage monthly (below BIR taxable threshold).
 *
 * Profile: ₱13,000/month, monthly, hired 2024-01-01, active.
 * Period:  monthly(2026, 5).
 *
 * Hand-computed expected values:
 *
 *   Basic / gross:   ₱13,000.00 → 1_300_000 cents.
 *
 *   SSS  (band MSC ₱13,000; lower=12_975_00, upper=13_025_00):
 *     employee_share = round(1_300_000 × 500 / 10_000) =  65_000 (₱650.00)
 *     employer_share = round(1_300_000 × 900 / 10_000) = 117_000 (₱1,170.00)
 *     employer EC    = 1_000 (MSC ≤ ₱14,500 → ₱10.00)
 *     contribution_base = 1_300_000 (MSC = ₱13,000.00)
 *
 *   PhilHealth (5%, in [floor=10_000, ceiling=100_000], 50/50 split):
 *     total = 1_300_000 × 500 / 10_000 = 65_000
 *     employee_share = 65_000 × 5_000 / 10_000 = 32_500 (₱325.00)
 *     employer_share = 65_000 - 32_500           = 32_500 (₱325.00)
 *
 *   Pag-IBIG (cap ₱10,000; salary > threshold ₱1,500 → upper rates 2%/2%):
 *     capped = min(1_300_000, 1_000_000) = 1_000_000
 *     employee_share = 1_000_000 × 200 / 10_000 = 20_000 (₱200.00)
 *     employer_share = 20_000 (₱200.00)
 *
 *   Taxable income = 1_300_000 − 65_000 − 32_500 − 20_000 = 1_182_500 (₱11,825.00)
 *
 *   BIR (monthly bracket 1: lower=0, upper=2_083_300, 0%):
 *     tax = 0.
 *
 *   Totals:
 *     total_employee_deductions     =  65_000 +  32_500 + 20_000 +    0 =   117_500 (₱1,175.00)
 *     total_employer_contributions  = 117_000 +   1_000 + 32_500 + 20_000 =   170_500 (₱1,705.00)
 *     net_pay                       = 1_300_000 − 117_500 = 1_182_500 (₱11,825.00)
 */
it('case 1: minimum wage monthly (below BIR threshold)', function () {
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 1_300_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->basicPay->centavos())->toBe(1_300_000)
        ->and($result->grossPay->centavos())->toBe(1_300_000)
        ->and($result->sssEmployee->centavos())->toBe(65_000)
        ->and($result->sssEmployer->centavos())->toBe(117_000)
        ->and($result->sssEmployerEc->centavos())->toBe(1_000)
        ->and($result->philhealthEmployee->centavos())->toBe(32_500)
        ->and($result->philhealthEmployer->centavos())->toBe(32_500)
        ->and($result->pagibigEmployee->centavos())->toBe(20_000)
        ->and($result->pagibigEmployer->centavos())->toBe(20_000)
        ->and($result->birWithholdingTax->centavos())->toBe(0)
        ->and($result->taxableIncome->centavos())->toBe(1_182_500)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(117_500)
        ->and($result->totalEmployerContributions->centavos())->toBe(170_500)
        ->and($result->netPay->centavos())->toBe(1_182_500);
});

/**
 * Case 2: Mid-bracket monthly (₱45,000).
 *
 * Profile: ₱45,000/month, monthly, hired 2024-01-01, active.
 * Period:  monthly(2026, 5).
 *
 * Hand-computed expected values:
 *
 *   Basic / gross: ₱45,000.00 → 4_500_000.
 *
 *   SSS (₱45,000 ≥ top-band lower 34_750_00 → MSC ₱35,000):
 *     employee_share = round(3_500_000 × 500 / 10_000) = 175_000 (₱1,750.00)
 *     employer_share = round(3_500_000 × 900 / 10_000) = 315_000 (₱3,150.00)
 *     employer EC    = 3_000 (MSC > ₱14,500 → ₱30.00)
 *
 *   PhilHealth: total = 4_500_000 × 500 / 10_000 = 225_000
 *     employee_share = 112_500 (₱1,125.00)
 *     employer_share = 225_000 − 112_500 = 112_500 (₱1,125.00)
 *
 *   Pag-IBIG: capped at 1_000_000. employee = 20_000 (₱200), employer = 20_000.
 *
 *   Taxable income = 4_500_000 − 175_000 − 112_500 − 20_000 = 4_192_500 (₱41,925.00)
 *
 *   BIR (monthly bracket 3: lower=3_333_300, upper=6_666_700, base=187_500, rate=2000bp):
 *     excess = 4_192_500 − 3_333_300 = 859_200
 *     taxOnExcess = 859_200 × 2000 / 10_000 = 1_718_400_000 / 10_000 = 171_840 (exact)
 *     tax = 187_500 + 171_840 = 359_340 (₱3,593.40)
 *
 *   Totals:
 *     total_employee_deductions    = 175_000 + 112_500 + 20_000 + 359_340 = 666_840 (₱6,668.40)
 *     total_employer_contributions = 315_000 +   3_000 + 112_500 + 20_000 = 450_500 (₱4,505.00)
 *     net_pay                      = 4_500_000 − 666_840                  = 3_833_160 (₱38,331.60)
 */
it('case 2: mid-bracket monthly (₱45,000)', function () {
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->basicPay->centavos())->toBe(4_500_000)
        ->and($result->grossPay->centavos())->toBe(4_500_000)
        ->and($result->sssEmployee->centavos())->toBe(175_000)
        ->and($result->sssEmployer->centavos())->toBe(315_000)
        ->and($result->sssEmployerEc->centavos())->toBe(3_000)
        ->and($result->philhealthEmployee->centavos())->toBe(112_500)
        ->and($result->philhealthEmployer->centavos())->toBe(112_500)
        ->and($result->pagibigEmployee->centavos())->toBe(20_000)
        ->and($result->pagibigEmployer->centavos())->toBe(20_000)
        ->and($result->birWithholdingTax->centavos())->toBe(359_340)
        ->and($result->taxableIncome->centavos())->toBe(4_192_500)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(666_840)
        ->and($result->totalEmployerContributions->centavos())->toBe(450_500)
        ->and($result->netPay->centavos())->toBe(3_833_160);
});

/**
 * Case 3: Top-bracket monthly (₱700,000).
 *
 * Profile: ₱700,000/month, monthly, hired 2024-01-01, active.
 * Period:  monthly(2026, 5).
 *
 * Hand-computed expected values:
 *
 *   Basic / gross: ₱700,000.00 → 70_000_000.
 *
 *   SSS (above top MSC → MSC ₱35,000):
 *     employee_share = 175_000, employer_share = 315_000, employer EC = 3_000.
 *
 *   PhilHealth (above ceiling → cap at 10_000_000):
 *     total = 10_000_000 × 500 / 10_000 = 500_000
 *     employee_share = 250_000 (₱2,500.00)
 *     employer_share = 500_000 − 250_000 = 250_000 (₱2,500.00)
 *
 *   Pag-IBIG: capped at 1_000_000. employee = 20_000, employer = 20_000.
 *
 *   Taxable income = 70_000_000 − 175_000 − 250_000 − 20_000 = 69_555_000 (₱695,550.00)
 *
 *   BIR (monthly top bracket: lower=66_666_700, upper=null, base=18_354_180, rate=3500bp):
 *     excess = 69_555_000 − 66_666_700 = 2_888_300
 *     taxOnExcess = 2_888_300 × 3_500 / 10_000 = 10_109_050_000 / 10_000 = 1_010_905 (exact)
 *     tax = 18_354_180 + 1_010_905 = 19_365_085 (₱193,650.85)
 *
 *   Totals:
 *     total_employee_deductions    = 175_000 + 250_000 + 20_000 + 19_365_085 = 19_810_085
 *     total_employer_contributions = 315_000 +   3_000 + 250_000 + 20_000    = 588_000 (₱5,880.00)
 *     net_pay                      = 70_000_000 − 19_810_085                 = 50_189_915 (₱501,899.15)
 */
it('case 3: top-bracket monthly (₱700,000)', function () {
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 70_000_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->basicPay->centavos())->toBe(70_000_000)
        ->and($result->grossPay->centavos())->toBe(70_000_000)
        ->and($result->sssEmployee->centavos())->toBe(175_000)
        ->and($result->sssEmployer->centavos())->toBe(315_000)
        ->and($result->sssEmployerEc->centavos())->toBe(3_000)
        ->and($result->philhealthEmployee->centavos())->toBe(250_000)
        ->and($result->philhealthEmployer->centavos())->toBe(250_000)
        ->and($result->pagibigEmployee->centavos())->toBe(20_000)
        ->and($result->pagibigEmployer->centavos())->toBe(20_000)
        ->and($result->birWithholdingTax->centavos())->toBe(19_365_085)
        ->and($result->taxableIncome->centavos())->toBe(69_555_000)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(19_810_085)
        ->and($result->totalEmployerContributions->centavos())->toBe(588_000)
        ->and($result->netPay->centavos())->toBe(50_189_915);
});

/**
 * Case 4: Semi-monthly first half ₱45,000.
 *
 * Profile: ₱45,000/month, semi_monthly, hired 2024-01-01, active.
 * Period:  semiMonthlyFirst(2026, 5)  — May 1 to May 15 inclusive (15 days).
 *
 * Hand-computed expected values:
 *
 *   Basic / gross: full-period basic = 4_500_000 / 2 = 2_250_000 (₱22,500.00).
 *
 *   SSS — full monthly basis ₱45,000 → top band, then halved:
 *     pre-half:  ee=175_000, er=315_000, ec=3_000
 *     halved:    ee= 87_500 (₱875.00), er=157_500 (₱1,575.00), ec=1_500 (₱15.00)
 *
 *   PhilHealth — full monthly basis, then halved:
 *     pre-half:  ee=112_500, er=112_500
 *     halved:    ee= 56_250 (₱562.50), er= 56_250 (₱562.50)
 *
 *   Pag-IBIG — full monthly basis, then halved:
 *     pre-half:  ee=20_000, er=20_000
 *     halved:    ee=10_000 (₱100.00), er=10_000 (₱100.00)
 *
 *   Taxable income = 2_250_000 − 87_500 − 56_250 − 10_000 = 2_096_250 (₱20,962.50)
 *
 *   BIR (semi_monthly bracket 3: lower=1_666_700, upper=3_333_300, base=93_750, rate=2000bp):
 *     excess = 2_096_250 − 1_666_700 = 429_550
 *     taxOnExcess = 429_550 × 2000 / 10_000 = 859_100_000 / 10_000 = 85_910 (exact)
 *     tax = 93_750 + 85_910 = 179_660 (₱1,796.60)
 *
 *   Totals:
 *     total_employee_deductions    =  87_500 +  56_250 + 10_000 + 179_660 = 333_410 (₱3,334.10)
 *     total_employer_contributions = 157_500 +   1_500 + 56_250 + 10_000  = 225_250 (₱2,252.50)
 *     net_pay                      = 2_250_000 − 333_410                   = 1_916_590 (₱19,165.90)
 */
it('case 4: semi-monthly first half ₱45,000', function () {
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'semi_monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::semiMonthlyFirst(2026, 5));

    expect($result->basicPay->centavos())->toBe(2_250_000)
        ->and($result->grossPay->centavos())->toBe(2_250_000)
        ->and($result->sssEmployee->centavos())->toBe(87_500)
        ->and($result->sssEmployer->centavos())->toBe(157_500)
        ->and($result->sssEmployerEc->centavos())->toBe(1_500)
        ->and($result->philhealthEmployee->centavos())->toBe(56_250)
        ->and($result->philhealthEmployer->centavos())->toBe(56_250)
        ->and($result->pagibigEmployee->centavos())->toBe(10_000)
        ->and($result->pagibigEmployer->centavos())->toBe(10_000)
        ->and($result->birWithholdingTax->centavos())->toBe(179_660)
        ->and($result->taxableIncome->centavos())->toBe(2_096_250)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(333_410)
        ->and($result->totalEmployerContributions->centavos())->toBe(225_250)
        ->and($result->netPay->centavos())->toBe(1_916_590);
});

/**
 * Case 5: Semi-monthly second half (Feb leap year) ₱45,000.
 *
 * Profile: ₱45,000/month, semi_monthly, hired 2020-01-01, active.
 * Period:  semiMonthlySecond(2028, 2)  — Feb 16 to Feb 29 inclusive (14 days).
 *
 * 2028 is the next leap year for which all four seeded statutory rates are
 * effective (SSS effective 2025-01-01 — 2024 would predate it). The point
 * here is NOT to test leap-year math; the engine halves a semi-monthly
 * basic regardless of day count when no hire/termination cuts the period.
 *
 * Hand-computed expected values: identical to Case 4 (period type drives
 * statutory halving and BIR bracket selection; day count does not).
 */
it('case 5: semi-monthly second half (Feb leap year) ₱45,000', function () {
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'semi_monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2020-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::semiMonthlySecond(2028, 2));

    expect($result->basicPay->centavos())->toBe(2_250_000)
        ->and($result->grossPay->centavos())->toBe(2_250_000)
        ->and($result->sssEmployee->centavos())->toBe(87_500)
        ->and($result->sssEmployer->centavos())->toBe(157_500)
        ->and($result->sssEmployerEc->centavos())->toBe(1_500)
        ->and($result->philhealthEmployee->centavos())->toBe(56_250)
        ->and($result->philhealthEmployer->centavos())->toBe(56_250)
        ->and($result->pagibigEmployee->centavos())->toBe(10_000)
        ->and($result->pagibigEmployer->centavos())->toBe(10_000)
        ->and($result->birWithholdingTax->centavos())->toBe(179_660)
        ->and($result->taxableIncome->centavos())->toBe(2_096_250)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(333_410)
        ->and($result->totalEmployerContributions->centavos())->toBe(225_250)
        ->and($result->netPay->centavos())->toBe(1_916_590);
});

/**
 * Case 6: Mid-month hire (May 15, ₱60,000).
 *
 * Profile: ₱60,000/month, monthly, hired 2026-05-15, active.
 * Period:  monthly(2026, 5)  — May 1 to May 31 inclusive (31 days).
 *
 * Hand-computed expected values:
 *
 *   Basic / gross — pro-rated:
 *     daysWorked = May 15..31 inclusive = 17.
 *     basic = 6_000_000 × 17 / 31 = 102_000_000 / 31.
 *       intdiv(102_000_000, 31) = 3_290_322  (31 × 3_290_322 = 101_999_982).
 *       remainder = 18.  2 × 18 = 36 > 31 → round away from zero.
 *       → 3_290_323 (₱32,903.23).
 *
 *   Statutory contributions are computed against the FULL monthly salary
 *   (₱60,000), NOT the pro-rated basic — regulators do not pro-rate.
 *
 *   SSS (₱60,000 → top band MSC ₱35,000):
 *     ee=175_000, er=315_000, ec=3_000.
 *
 *   PhilHealth: total = 6_000_000 × 500 / 10_000 = 300_000
 *     ee=150_000 (₱1,500), er=150_000.
 *
 *   Pag-IBIG: capped at 1_000_000. ee=20_000, er=20_000.
 *
 *   Taxable income = 3_290_323 − 175_000 − 150_000 − 20_000 = 2_945_323 (₱29,453.23)
 *
 *   BIR (monthly bracket 2: lower=2_083_300, upper=3_333_300, base=0, rate=1500bp):
 *     excess = 2_945_323 − 2_083_300 = 862_023
 *     taxOnExcess = 862_023 × 1_500 / 10_000 = 1_293_034_500 / 10_000.
 *       intdiv = 129_303.  remainder = 4_500.  2 × 4_500 = 9_000 < 10_000
 *       → round toward zero → 129_303.
 *     tax = 0 + 129_303 = 129_303 (₱1,293.03).
 *
 *   Totals:
 *     total_employee_deductions    = 175_000 + 150_000 + 20_000 + 129_303 = 474_303 (₱4,743.03)
 *     total_employer_contributions = 315_000 +   3_000 + 150_000 + 20_000 = 488_000 (₱4,880.00)
 *     net_pay                      = 3_290_323 − 474_303                  = 2_816_020 (₱28,160.20)
 */
it('case 6: mid-month hire (May 15, ₱60,000)', function () {
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 6_000_000,
        'date_hired' => '2026-05-15',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->basicPay->centavos())->toBe(3_290_323)
        ->and($result->grossPay->centavos())->toBe(3_290_323)
        ->and($result->sssEmployee->centavos())->toBe(175_000)
        ->and($result->sssEmployer->centavos())->toBe(315_000)
        ->and($result->sssEmployerEc->centavos())->toBe(3_000)
        ->and($result->philhealthEmployee->centavos())->toBe(150_000)
        ->and($result->philhealthEmployer->centavos())->toBe(150_000)
        ->and($result->pagibigEmployee->centavos())->toBe(20_000)
        ->and($result->pagibigEmployer->centavos())->toBe(20_000)
        ->and($result->birWithholdingTax->centavos())->toBe(129_303)
        ->and($result->taxableIncome->centavos())->toBe(2_945_323)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(474_303)
        ->and($result->totalEmployerContributions->centavos())->toBe(488_000)
        ->and($result->netPay->centavos())->toBe(2_816_020);
});

/**
 * Case 7: Mid-month termination (May 20, ₱60,000).
 *
 * Profile: ₱60,000/month, monthly, hired 2024-01-01, terminated 2026-05-20.
 * Period:  monthly(2026, 5)  — May 1 to May 31 inclusive (31 days).
 *
 * Hand-computed expected values:
 *
 *   Basic / gross — pro-rated:
 *     daysWorked = May 1..20 inclusive = 20.
 *     basic = 6_000_000 × 20 / 31 = 120_000_000 / 31.
 *       intdiv(120_000_000, 31) = 3_870_967  (31 × 3_870_967 = 119_999_977).
 *       remainder = 23.  2 × 23 = 46 > 31 → round away from zero.
 *       → 3_870_968 (₱38,709.68).
 *
 *   Statutory contributions — full monthly basis (₱60,000): same as Case 6.
 *     SSS:        ee=175_000, er=315_000, ec=3_000.
 *     PhilHealth: ee=150_000, er=150_000.
 *     Pag-IBIG:   ee= 20_000, er= 20_000.
 *
 *   Taxable income = 3_870_968 − 175_000 − 150_000 − 20_000 = 3_525_968 (₱35,259.68)
 *
 *   BIR (monthly bracket 3: lower=3_333_300, upper=6_666_700, base=187_500, rate=2000bp):
 *     excess = 3_525_968 − 3_333_300 = 192_668
 *     taxOnExcess = 192_668 × 2_000 / 10_000 = 385_336_000 / 10_000.
 *       intdiv = 38_533.  remainder = 6_000.  2 × 6_000 = 12_000 > 10_000
 *       → round away from zero → 38_534.
 *     tax = 187_500 + 38_534 = 226_034 (₱2,260.34).
 *
 *   Totals:
 *     total_employee_deductions    = 175_000 + 150_000 + 20_000 + 226_034 = 571_034 (₱5,710.34)
 *     total_employer_contributions = 315_000 +   3_000 + 150_000 + 20_000 = 488_000 (₱4,880.00)
 *     net_pay                      = 3_870_968 − 571_034                  = 3_299_934 (₱32,999.34)
 */
it('case 7: mid-month termination (May 20, ₱60,000)', function () {
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 6_000_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => '2026-05-20',
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->basicPay->centavos())->toBe(3_870_968)
        ->and($result->grossPay->centavos())->toBe(3_870_968)
        ->and($result->sssEmployee->centavos())->toBe(175_000)
        ->and($result->sssEmployer->centavos())->toBe(315_000)
        ->and($result->sssEmployerEc->centavos())->toBe(3_000)
        ->and($result->philhealthEmployee->centavos())->toBe(150_000)
        ->and($result->philhealthEmployer->centavos())->toBe(150_000)
        ->and($result->pagibigEmployee->centavos())->toBe(20_000)
        ->and($result->pagibigEmployer->centavos())->toBe(20_000)
        ->and($result->birWithholdingTax->centavos())->toBe(226_034)
        ->and($result->taxableIncome->centavos())->toBe(3_525_968)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(571_034)
        ->and($result->totalEmployerContributions->centavos())->toBe(488_000)
        ->and($result->netPay->centavos())->toBe(3_299_934);
});

/**
 * Case 8: Inactive profile.
 *
 * Profile: ₱60,000/month, monthly, hired 2024-01-01, active=false.
 * Period:  monthly(2026, 5).
 *
 * Hand-computed expected values:
 *   ALL Money fields zero. Audit lines empty.
 *
 * The contribution rows ARE seeded in this case (beforeEach runs the seeder),
 * but the engine's basic-pay short-circuit fires first — an inactive employee
 * yields Money::zero() before the resolver is consulted. This case differs
 * from the existing zero-rate test in PayrollComputationServiceTest by having
 * rates present yet still returning a uniform zero result.
 */
it('case 8: inactive profile yields all zeros', function () {
    $profile = EmployeeProfile::factory()->create([
        'is_active' => false,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 6_000_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->basicPay->centavos())->toBe(0)
        ->and($result->grossPay->centavos())->toBe(0)
        ->and($result->sssEmployee->centavos())->toBe(0)
        ->and($result->sssEmployer->centavos())->toBe(0)
        ->and($result->sssEmployerEc->centavos())->toBe(0)
        ->and($result->philhealthEmployee->centavos())->toBe(0)
        ->and($result->philhealthEmployer->centavos())->toBe(0)
        ->and($result->pagibigEmployee->centavos())->toBe(0)
        ->and($result->pagibigEmployer->centavos())->toBe(0)
        ->and($result->birWithholdingTax->centavos())->toBe(0)
        ->and($result->taxableIncome->centavos())->toBe(0)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(0)
        ->and($result->totalEmployerContributions->centavos())->toBe(0)
        ->and($result->netPay->centavos())->toBe(0);
});

/**
 * Case 9: PhilHealth floor (₱8,000).
 *
 * Profile: ₱8,000/month, monthly, hired 2024-01-01, active.
 * Period:  monthly(2026, 5).
 *
 * Hand-computed expected values:
 *
 *   Basic / gross: ₱8,000.00 → 800_000.
 *
 *   SSS (band MSC ₱8,000; lower=775_000, upper=825_000):
 *     employee_share = round(800_000 × 500 / 10_000) = 40_000 (₱400.00)
 *     employer_share = round(800_000 × 900 / 10_000) = 72_000 (₱720.00)
 *     employer EC    = 1_000 (MSC ≤ ₱14,500 → ₱10.00)
 *
 *   PhilHealth (BELOW floor → cap UP to floor 1_000_000):
 *     total = 1_000_000 × 500 / 10_000 = 50_000
 *     employee_share = 25_000 (₱250.00)
 *     employer_share = 50_000 − 25_000 = 25_000 (₱250.00)
 *
 *   Pag-IBIG (cap is max_msc ₱10,000 — HIGHER than salary, so input passes
 *   through uncapped at 800_000; salary > threshold ₱1,500 → upper rates):
 *     employee_share = 800_000 × 200 / 10_000 = 16_000 (₱160.00)
 *     employer_share = 16_000 (₱160.00)
 *
 *   Taxable income = 800_000 − 40_000 − 25_000 − 16_000 = 719_000 (₱7,190.00)
 *
 *   BIR (monthly bracket 1, 0%): tax = 0.
 *
 *   Totals:
 *     total_employee_deductions    = 40_000 + 25_000 + 16_000 +    0 =  81_000 (₱810.00)
 *     total_employer_contributions = 72_000 +  1_000 + 25_000 + 16_000 = 114_000 (₱1,140.00)
 *     net_pay                      = 800_000 − 81_000                  = 719_000 (₱7,190.00)
 */
it('case 9: PhilHealth floor (₱8,000)', function () {
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 800_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->basicPay->centavos())->toBe(800_000)
        ->and($result->grossPay->centavos())->toBe(800_000)
        ->and($result->sssEmployee->centavos())->toBe(40_000)
        ->and($result->sssEmployer->centavos())->toBe(72_000)
        ->and($result->sssEmployerEc->centavos())->toBe(1_000)
        ->and($result->philhealthEmployee->centavos())->toBe(25_000)
        ->and($result->philhealthEmployer->centavos())->toBe(25_000)
        ->and($result->pagibigEmployee->centavos())->toBe(16_000)
        ->and($result->pagibigEmployer->centavos())->toBe(16_000)
        ->and($result->birWithholdingTax->centavos())->toBe(0)
        ->and($result->taxableIncome->centavos())->toBe(719_000)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(81_000)
        ->and($result->totalEmployerContributions->centavos())->toBe(114_000)
        ->and($result->netPay->centavos())->toBe(719_000);
});

/**
 * Case 10: Pag-IBIG cap (₱20,000).
 *
 * Profile: ₱20,000/month, monthly, hired 2024-01-01, active.
 * Period:  monthly(2026, 5).
 *
 * Hand-computed expected values:
 *
 *   Basic / gross: ₱20,000.00 → 2_000_000.
 *
 *   SSS (band MSC ₱20,000; lower=1_975_000, upper=2_025_000):
 *     employee_share = round(2_000_000 × 500 / 10_000) = 100_000 (₱1,000.00)
 *     employer_share = round(2_000_000 × 900 / 10_000) = 180_000 (₱1,800.00)
 *     employer EC    = 3_000 (MSC > ₱14,500 → ₱30.00)
 *
 *   PhilHealth: total = 2_000_000 × 500 / 10_000 = 100_000
 *     employee_share = 50_000 (₱500.00)
 *     employer_share = 100_000 − 50_000 = 50_000 (₱500.00)
 *
 *   Pag-IBIG (capped at max_msc 1_000_000; salary > threshold → upper rates):
 *     employee_share = 1_000_000 × 200 / 10_000 = 20_000 (₱200.00)
 *     employer_share = 20_000 (₱200.00)
 *
 *   Taxable income = 2_000_000 − 100_000 − 50_000 − 20_000 = 1_830_000 (₱18,300.00)
 *
 *   BIR (monthly bracket 1: lower=0, upper=2_083_300, 0%): tax = 0.
 *
 *   Totals:
 *     total_employee_deductions    = 100_000 + 50_000 + 20_000 +    0 = 170_000 (₱1,700.00)
 *     total_employer_contributions = 180_000 +  3_000 + 50_000 + 20_000 = 253_000 (₱2,530.00)
 *     net_pay                      = 2_000_000 − 170_000                = 1_830_000 (₱18,300.00)
 */
it('case 10: Pag-IBIG cap (₱20,000)', function () {
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 2_000_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->basicPay->centavos())->toBe(2_000_000)
        ->and($result->grossPay->centavos())->toBe(2_000_000)
        ->and($result->sssEmployee->centavos())->toBe(100_000)
        ->and($result->sssEmployer->centavos())->toBe(180_000)
        ->and($result->sssEmployerEc->centavos())->toBe(3_000)
        ->and($result->philhealthEmployee->centavos())->toBe(50_000)
        ->and($result->philhealthEmployer->centavos())->toBe(50_000)
        ->and($result->pagibigEmployee->centavos())->toBe(20_000)
        ->and($result->pagibigEmployer->centavos())->toBe(20_000)
        ->and($result->birWithholdingTax->centavos())->toBe(0)
        ->and($result->taxableIncome->centavos())->toBe(1_830_000)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(170_000)
        ->and($result->totalEmployerContributions->centavos())->toBe(253_000)
        ->and($result->netPay->centavos())->toBe(1_830_000);
});
