<?php

declare(strict_types=1);

use App\Actions\Payroll\ApplyUnpaidDays;
use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use App\Services\Payroll\Breakdowns\UnpaidDaysReduction;
use App\Services\Payroll\PayrollComputationService;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;
use Database\Seeders\StatutoryContributionSeeder;

/*
 * --------------------------------------------------------------------------
 * Week 7 — End-to-end composition acceptance test (Chunk 7 QA closure)
 * --------------------------------------------------------------------------
 *
 * One employee profile, ALL Week-7 inputs simultaneously: 2 allowances
 * (taxable + de-minimis non-taxable), 2 custom deductions (fixed non-taxable
 * + percent taxable), 1 active loan, 1 in-period taxable adjustment, and a
 * mocked 2-day unpaid-leave reduction. Asserts every Money field and the
 * audit-line count centavo-exact against hand-derived math.
 *
 * Sister tests cover individual composition concerns (PayrollComputationServiceTest)
 * and 15 single-feature reference cases (ReferenceCasesTest). This test exists
 * specifically to prove the engine reconciles ALL inputs together — the worst
 * place for a bug to hide is the interaction surface between two correct
 * actions.
 *
 * --------------------------------------------------------------------------
 * Hand-derived math (May 2026, monthly period, 31 days)
 * --------------------------------------------------------------------------
 *
 * Profile:
 *   basic_salary_centavos = 3_000_000  (PHP 30,000)
 *   pay_frequency         = monthly
 *   date_hired            = 2024-01-01 (active well before period.start())
 *
 * Inputs:
 *   Allowances:
 *     - rice_subsidy   : non-taxable, de-minimis (cap=null), 150_000 / period
 *     - transportation : taxable, not de-minimis,           200_000 / period
 *   Custom deductions:
 *     - HMO            : fixed, non-taxable, employee-source,  50_000
 *     - Percent levy   : 1.00% (100bp) of gross, taxable, employee-source
 *   Loan:
 *     - outstanding 4_500_000, monthly amortization 500_000 (well above clamp)
 *   Adjustment:
 *     - addition, taxable, 300_000, applies_on=2026-05-15 (in-period)
 *   Unpaid days:
 *     - mocked: 2 unpaid days, reduction = 200_000 (= 30_000 * 2 / 30 in pesos)
 *       Mock substitutes ApplyUnpaidDays via container instance() — possible
 *       since Chunk-6 backend dropped `final` from the action.
 *
 * Step-by-step (mirrors PayrollComputationService::compute() order):
 *
 *   1. basicPay (initial)         = 3_000_000
 *      unpaidDaysReduction        =   200_000
 *      basicPay (post-reduction)  = 2_800_000
 *
 *   2. monthlyBasis (statutory)   = 3_000_000  (FULL salary, NOT reduced;
 *                                                statutory bases never narrow
 *                                                with allowances/adjustments
 *                                                or unpaid days)
 *
 *   3. SSS @ MSC 30_000 band:
 *        msc                = 3_000_000
 *        employee_share     = round(3_000_000 * 500 / 10_000) =  150_000
 *        employer_share     = round(3_000_000 * 900 / 10_000) =  270_000
 *        employer_ec        = 3_000  (MSC > 14_500 -> PHP 30)
 *        contribution_base  = 3_000_000
 *
 *   4. PhilHealth (5%, in [floor=1_000_000, ceiling=10_000_000], 50/50):
 *        total              = 3_000_000 * 500 / 10_000 = 150_000
 *        employee_share     = 150_000 * 5_000 / 10_000 =  75_000
 *        employer_share     = 150_000 - 75_000        =  75_000
 *
 *   5. Pag-IBIG (above PHP 1,500 threshold -> upper rates 2%/2%; cap 1_000_000):
 *        capped             = min(3_000_000, 1_000_000) = 1_000_000
 *        employee_share     = 1_000_000 * 200 / 10_000 = 20_000
 *        employer_share     = 20_000
 *
 *   6. Allowances:
 *        taxable            =   200_000  (transportation)
 *        nonTaxable         =   150_000  (rice subsidy)
 *
 *   7. Adjustments (one in-period addition, taxable):
 *        taxableAdditions    =   300_000
 *        nonTaxableAdditions =         0
 *        deductions          =         0
 *
 *   8. grossPay = basicPay + taxAlw + nonTaxAlw + taxAdjAdd + nonTaxAdjAdd
 *               = 2_800_000 + 200_000 + 150_000 + 300_000 + 0
 *               = 3_450_000
 *
 *   9. Custom deductions (percent basis = grossPay = 3_450_000):
 *        HMO        : fixed 50_000, non-taxable -> employee += 50_000;
 *                     taxableImpact unchanged.
 *        Percent    : 3_450_000 * 100 / 10_000 = 345_000_000 / 10_000.
 *                     intdiv = 34_500, remainder 0 -> 34_500 (exact).
 *                     taxable -> employee += 34_500; taxableImpact += 34_500.
 *        employee   = 50_000 + 34_500       = 84_500
 *        employer   = 0
 *        taxableImpact = 34_500
 *
 *  10. Loans:
 *        amortization = min(500_000, 4_500_000) = 500_000
 *        loans.total  = 500_000
 *
 *  11. Taxable income = basicPay + taxAlw + taxAdjAdd
 *                       - sssEE - phEE - pagEE - taxableImpact
 *                     = 2_800_000 + 200_000 + 300_000
 *                       - 150_000 - 75_000 - 20_000 - 34_500
 *                     = 3_300_000 - 279_500
 *                     = 3_020_500
 *
 *  12. BIR (monthly bracket 2: lower=2_083_300, upper=3_333_300, base=0,
 *           rate=1500bp, excess_over=2_083_300):
 *        excess          = 3_020_500 - 2_083_300 = 937_200
 *        taxOnExcess     = 937_200 * 1500 / 10_000 = 1_405_800_000 / 10_000
 *                          intdiv = 140_580, remainder 0 -> 140_580 (exact)
 *        birWithholding  = 0 + 140_580 = 140_580
 *
 *  13. Aggregates:
 *        totalEmployeeDeductions
 *          = sssEE + phEE + pagEE + bir + customEE + loanTotal + adjDeductions
 *          = 150_000 + 75_000 + 20_000 + 140_580 + 84_500 + 500_000 + 0
 *          = 970_080
 *
 *        totalEmployerContributions
 *          = sssER + sssER_EC + phER + pagER + customER
 *          = 270_000 + 3_000 + 75_000 + 20_000 + 0
 *          = 368_000
 *
 *  14. netPay = grossPay - totalEmployeeDeductions
 *             = 3_450_000 - 970_080
 *             = 2_479_920
 *
 *  15. Audit lines (16 total):
 *        Week-6 canonical (9): basic, sssEE, phEE, pagEE, bir,
 *                              sssER, sssER_EC, phER, pagER
 *        Week-7 (7):           1 allowance taxable, 1 allowance non-taxable,
 *                              1 adjustment addition, 0 adjustment deduction,
 *                              2 custom deductions, 1 loan, 1 unpaid days
 *
 * If the engine output ever disagrees with these figures, do NOT silently
 * adjust the expected centavos. The 15 reference cases prove the per-field
 * math is right; a divergence here means the composition order or one of the
 * Apply* actions has shifted. Re-trace the steps before changing the test.
 */

it('composes every Week-7 input into a single centavo-exact payslip', function () {
    $this->seed(StatutoryContributionSeeder::class);

    // ----- 1. Profile ----------------------------------------------------

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 3_000_000, // PHP 30,000
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    // ----- 2. Allowance subscriptions -----------------------------------

    $rice = Allowance::factory()->riceSubsidy()->create();
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $profile->id,
        'allowance_id' => $rice->id,
        'amount_centavos' => 150_000,
        'schedule' => 'every_run',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $transportation = Allowance::factory()->transportation()->create();
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $profile->id,
        'allowance_id' => $transportation->id,
        'amount_centavos' => 200_000,
        'schedule' => 'every_run',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    // ----- 3. Custom deductions ------------------------------------------

    $hmo = DeductionType::factory()->hmo()->create();
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
        'deduction_type_id' => $hmo->id,
        'amount_centavos' => 50_000,
        'percent_basis_points' => null,
        'schedule' => 'every_run',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    // Percent-method deduction. The DeductionType row carries
    // calc_method='percent'; the EmployeeDeduction row carries the BP value.
    // is_taxable=true so the percent amount reduces the BIR base.
    $percentType = DeductionType::factory()->create([
        'code' => 'community_levy',
        'name' => 'Community Levy',
        'is_taxable' => true,
        'calc_method' => DeductionType::CALC_PERCENT,
        'source' => DeductionType::SOURCE_EMPLOYEE,
        'is_active' => true,
    ]);
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
        'deduction_type_id' => $percentType->id,
        'amount_centavos' => null,
        'percent_basis_points' => 100, // 1.00%
        'schedule' => 'every_run',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    // ----- 4. Loan -------------------------------------------------------

    $loan = EmployeeLoan::factory()->create([
        'employee_profile_id' => $profile->id,
        'principal_centavos' => 6_000_000,            // PHP 60,000
        'outstanding_balance_centavos' => 4_500_000,  // PHP 45,000 remaining
        'monthly_amortization_centavos' => 500_000,   // PHP 5,000 / period
        'schedule' => 'every_run',
        'started_on' => '2025-01-01',
        'closed_on' => null,
        'lock_version' => 0,
    ]);

    // ----- 5. Adjustment (one-off taxable bonus, in-period) -------------

    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_ADDITION,
        'is_taxable' => true,
        'amount_centavos' => 300_000, // PHP 3,000
        'applies_on' => '2026-05-15',
        'label' => 'Performance bonus',
    ]);

    // ----- 6. Unpaid days mock -------------------------------------------
    //
    // Production ApplyUnpaidDays is a stubbed zero-returning action pending
    // the LMS leave-bridge contract. We swap it out via Mockery to exercise
    // the engine's reduction path centavo-exact. Possible because Chunk-6
    // backend removed `final` from ApplyUnpaidDays for exactly this purpose.

    $this->instance(ApplyUnpaidDays::class, Mockery::mock(
        ApplyUnpaidDays::class,
        function ($mock): void {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(new UnpaidDaysReduction(
                    reduction: Money::fromCentavos(200_000), // PHP 2,000 = 30_000 * 2/30
                    unpaidDays: 2,
                    line: new PayrollLineItem(
                        code: PayrollLineItem::CODE_UNPAID_DAYS,
                        label: 'Unpaid leave (2 day(s))',
                        amount: Money::fromCentavos(200_000),
                        bucket: PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
                        meta: ['unpaid_days' => 2],
                    ),
                ));
        },
    ));

    // ----- 7. Compute ----------------------------------------------------

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // ----- 8. Centavo-exact aggregate assertions ------------------------

    // Earnings
    expect($result->basicPay->centavos())->toBe(2_800_000)
        ->and($result->allowancesTaxable->centavos())->toBe(200_000)
        ->and($result->allowancesNonTaxable->centavos())->toBe(150_000)
        ->and($result->adjustmentTaxableAdditions->centavos())->toBe(300_000)
        ->and($result->adjustmentNonTaxableAdditions->centavos())->toBe(0)
        ->and($result->adjustmentDeductions->centavos())->toBe(0)
        ->and($result->grossPay->centavos())->toBe(3_450_000);

    // Statutory employee shares (full monthly basis, not reduced)
    expect($result->sssEmployee->centavos())->toBe(150_000)
        ->and($result->philhealthEmployee->centavos())->toBe(75_000)
        ->and($result->pagibigEmployee->centavos())->toBe(20_000);

    // Statutory employer shares
    expect($result->sssEmployer->centavos())->toBe(270_000)
        ->and($result->sssEmployerEc->centavos())->toBe(3_000)
        ->and($result->philhealthEmployer->centavos())->toBe(75_000)
        ->and($result->pagibigEmployer->centavos())->toBe(20_000);

    // Custom employee deductions = HMO (50_000) + 1% percent (34_500)
    expect($result->customDeductionsEmployee->centavos())->toBe(84_500)
        ->and($result->customDeductionsEmployer->centavos())->toBe(0);

    // Loan amortization (clamped to monthly = 500_000, balance > clamp)
    expect($result->loanDeductions->centavos())->toBe(500_000);

    // Unpaid days mirror the mock
    expect($result->unpaidDaysReduction->centavos())->toBe(200_000)
        ->and($result->unpaidDaysCount)->toBe(2);

    // Taxable income — only the percent custom deduction reduces BIR base;
    // HMO is non-taxable so its 50_000 does NOT enter taxableImpact.
    // = 2_800_000 + 200_000 + 300_000 - 150_000 - 75_000 - 20_000 - 34_500
    // = 3_020_500
    expect($result->taxableIncome->centavos())->toBe(3_020_500);

    // BIR @ taxable=3_020_500 in monthly bracket 2 (15% over 2_083_300):
    // (3_020_500 - 2_083_300) * 0.15 = 937_200 * 1500 / 10_000 = 140_580.
    expect($result->birWithholdingTax->centavos())->toBe(140_580);

    // Totals
    // sssEE + phEE + pagEE + bir + customEE + loan + adjDeductions
    // = 150_000 + 75_000 + 20_000 + 140_580 + 84_500 + 500_000 + 0
    // = 970_080
    expect($result->totalEmployeeDeductions->centavos())->toBe(970_080);

    // sssER + ec + phER + pagER + customER
    // = 270_000 + 3_000 + 75_000 + 20_000 + 0
    // = 368_000
    expect($result->totalEmployerContributions->centavos())->toBe(368_000);

    // Net pay = gross - totalEE = 3_450_000 - 970_080 = 2_479_920
    expect($result->netPay->centavos())->toBe(2_479_920);

    // ----- 9. Audit-line shape -------------------------------------------

    // 9 canonical Week-6 + 1 allowance taxable + 1 allowance non-taxable
    // + 1 adjustment addition + 2 custom deductions + 1 loan + 1 unpaid days
    expect($result->auditLines)->toHaveCount(16);

    $codes = array_map(
        fn (PayrollLineItem $line): string => $line->code,
        $result->auditLines,
    );

    // Canonical Week-6 prefix (positions 0..8).
    expect(array_slice($codes, 0, 9))->toBe([
        PayrollLineItem::CODE_BASIC_PAY,
        PayrollLineItem::CODE_SSS_EMPLOYEE,
        PayrollLineItem::CODE_PHILHEALTH_EMPLOYEE,
        PayrollLineItem::CODE_PAGIBIG_EMPLOYEE,
        PayrollLineItem::CODE_BIR_WITHHOLDING,
        PayrollLineItem::CODE_SSS_EMPLOYER,
        PayrollLineItem::CODE_SSS_EMPLOYER_EC,
        PayrollLineItem::CODE_PHILHEALTH_EMPLOYER,
        PayrollLineItem::CODE_PAGIBIG_EMPLOYER,
    ]);

    // Week-7 suffix: allowances (taxable then non-taxable), then adjustment
    // additions, then custom deductions (insertion order), then loans,
    // then unpaid-days (engine appends last).
    expect(array_slice($codes, 9))->toBe([
        PayrollLineItem::CODE_ALLOWANCE_TAXABLE,
        PayrollLineItem::CODE_ALLOWANCE_NON_TAXABLE,
        PayrollLineItem::CODE_ADJUSTMENT_ADDITION,
        PayrollLineItem::CODE_CUSTOM_DEDUCTION,
        PayrollLineItem::CODE_CUSTOM_DEDUCTION,
        PayrollLineItem::CODE_LOAN_AMORTIZATION,
        PayrollLineItem::CODE_UNPAID_DAYS,
    ]);

    // Loan is read-only at compute time — outstanding_balance must NOT decrement.
    $loan->refresh();
    expect($loan->outstanding_balance_centavos)->toBe(4_500_000)
        ->and($loan->lock_version)->toBe(0);

    // Reconciliation invariants — totals derive from per-action shares.
    expect($result->totalEmployeeDeductions->centavos())->toBe(
        $result->sssEmployee->centavos()
        + $result->philhealthEmployee->centavos()
        + $result->pagibigEmployee->centavos()
        + $result->birWithholdingTax->centavos()
        + $result->customDeductionsEmployee->centavos()
        + $result->loanDeductions->centavos()
        + $result->adjustmentDeductions->centavos()
    );

    expect($result->totalEmployerContributions->centavos())->toBe(
        $result->sssEmployer->centavos()
        + $result->sssEmployerEc->centavos()
        + $result->philhealthEmployer->centavos()
        + $result->pagibigEmployer->centavos()
        + $result->customDeductionsEmployer->centavos()
    );

    expect($result->netPay->centavos())->toBe(
        $result->grossPay->centavos() - $result->totalEmployeeDeductions->centavos()
    );

    // gross = basic + taxAlw + nonTaxAlw + taxAdjAdd + nonTaxAdjAdd
    expect($result->grossPay->centavos())->toBe(
        $result->basicPay->centavos()
        + $result->allowancesTaxable->centavos()
        + $result->allowancesNonTaxable->centavos()
        + $result->adjustmentTaxableAdditions->centavos()
        + $result->adjustmentNonTaxableAdditions->centavos()
    );
});
