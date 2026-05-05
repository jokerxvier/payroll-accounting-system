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
use App\Models\Pas\StatutoryContribution;
use App\Services\Payroll\Breakdowns\UnpaidDaysReduction;
use App\Services\Payroll\PayrollComputationResult;
use App\Services\Payroll\PayrollComputationService;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/*
 * Feature tests for PayrollComputationService — the composer that wires the
 * five computation actions into a single PayrollComputationResult. Tests are
 * exercised through the service container (`app(PayrollComputationService::class)`)
 * to verify auto-wired DI works end-to-end. RefreshDatabase is inherited from
 * tests/Pest.php for everything under tests/Feature.
 *
 * Reference math is hand-derived against the seeded factory states:
 *
 *   - SSS @ ₱45k or ₱60k (top band, lower=19_750.00):
 *       ee_share=900.00 (regular 900 + aux 0), er_share=1900.00,
 *       er_ec=30.00, contribution_base (MSC) = 20_000.00
 *
 *   - PhilHealth: rate_bp=500 (5%), floor=10_000 / ceiling=10_000_000 cents,
 *     50/50 split. ee_share = capped × 5% × 50%.
 *
 *   - Pag-IBIG: tiered, max_msc=5_000.00. Above threshold the upper rates
 *     200bp / 200bp apply to the capped MSC. For ₱45k → ee=10_000 / er=10_000.
 *
 *   - BIR (monthly, top bracket): base=1_875.00, excess_rate_bp=2000,
 *     excess_over=33_333.00. tax = base + (taxable - 33_333.00) × 20%.
 */

it('computes a full result for an active employee with all contributions seeded', function () {
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->philhealth()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->pagibig()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000, // ₱45,000.00
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    $service = app(PayrollComputationService::class);
    $result = $service->compute($profile, $period);

    expect($result)->toBeInstanceOf(PayrollComputationResult::class);

    // Basic / gross — Week 6 has no allowances, so gross == basic.
    expect($result->basicPay->centavos())->toBe(4_500_000)
        ->and($result->grossPay->centavos())->toBe(4_500_000);

    // Statutory employee shares (full monthly basis applies even though the
    // period is monthly here; the same field is half for semi-monthly).
    expect($result->sssEmployee->centavos())->toBe(90_000)
        ->and($result->philhealthEmployee->centavos())->toBe(112_500)
        ->and($result->pagibigEmployee->centavos())->toBe(10_000);

    // Taxable income = gross − employee statutory deductions.
    $expectedTaxable = 4_500_000 - 90_000 - 112_500 - 10_000;
    expect($result->taxableIncome->centavos())->toBe($expectedTaxable)
        ->and($expectedTaxable)->toBe(4_287_500);

    // BIR (monthly top bracket): 187_500 + (4_287_500 - 3_333_300) × 20%
    //                          = 187_500 + 954_200 × 2000 / 10_000
    //                          = 187_500 + 190_840 = 378_340.
    expect($result->birWithholdingTax->centavos())->toBe(378_340);

    // Employer-side aggregates.
    expect($result->sssEmployer->centavos())->toBe(190_000)
        ->and($result->sssEmployerEc->centavos())->toBe(3_000)
        ->and($result->philhealthEmployer->centavos())->toBe(112_500)
        ->and($result->pagibigEmployer->centavos())->toBe(10_000);

    // Aggregates derive from the per-strategy shares — pin them explicitly.
    expect($result->totalEmployeeDeductions->centavos())
        ->toBe(90_000 + 112_500 + 10_000 + 378_340)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(590_840);

    expect($result->totalEmployerContributions->centavos())
        ->toBe(190_000 + 3_000 + 112_500 + 10_000)
        ->and($result->totalEmployerContributions->centavos())->toBe(315_500);

    // Net pay = gross − total employee deductions (BIR included).
    expect($result->netPay->centavos())->toBe(4_500_000 - 590_840)
        ->and($result->netPay->centavos())->toBe(3_909_160);

    // Reconciliation invariants — totals must equal sums-of-fields.
    expect($result->totalEmployeeDeductions->centavos())
        ->toBe(
            $result->sssEmployee->centavos()
            + $result->philhealthEmployee->centavos()
            + $result->pagibigEmployee->centavos()
            + $result->birWithholdingTax->centavos(),
        );

    expect($result->totalEmployerContributions->centavos())
        ->toBe(
            $result->sssEmployer->centavos()
            + $result->sssEmployerEc->centavos()
            + $result->philhealthEmployer->centavos()
            + $result->pagibigEmployer->centavos(),
        );

    // Audit lines — canonical order with 9 entries.
    expect($result->auditLines)->toHaveCount(9);

    $codes = array_map(fn (PayrollLineItem $line): string => $line->code, $result->auditLines);
    expect($codes)->toBe([
        PayrollLineItem::CODE_BASIC_PAY,
        'SSS_EMPLOYEE',
        'PHILHEALTH_EMPLOYEE',
        'PAGIBIG_EMPLOYEE',
        'BIR_WITHHOLDING_EMPLOYEE',
        'SSS_EMPLOYER',
        'SSS_EMPLOYER_EC',
        'PHILHEALTH_EMPLOYER',
        'PAGIBIG_EMPLOYER',
    ]);

    // Bucket assignments per line.
    $buckets = array_map(fn (PayrollLineItem $line): string => $line->bucket, $result->auditLines);
    expect($buckets)->toBe([
        PayrollLineItem::BUCKET_EARNING,
        PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
        PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
        PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
        PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
        PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION,
        PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION,
        PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION,
        PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION,
    ]);

    // Meta payloads — basic carries null; deductions carry contribution-base
    // diagnostics; BIR carries the taxable-income figure.
    expect($result->auditLines[0]->meta)->toBeNull();

    // SSS taxable amount is the band's contribution_base (MSC = ₱20,000.00).
    expect($result->auditLines[1]->meta)
        ->toBe(['contribution_base_centavos' => 2_000_000]);
    // PhilHealth taxable amount is the input itself (within range).
    expect($result->auditLines[2]->meta)
        ->toBe(['contribution_base_centavos' => 4_500_000]);
    // Pag-IBIG taxable amount is the capped MSC (₱5,000.00).
    expect($result->auditLines[3]->meta)
        ->toBe(['contribution_base_centavos' => 500_000]);
    // BIR carries the post-statutory taxable income.
    expect($result->auditLines[4]->meta)
        ->toBe(['taxable_income_centavos' => 4_287_500]);

    // Employer lines carry no meta (no derived basis to surface).
    expect($result->auditLines[5]->meta)->toBeNull()
        ->and($result->auditLines[6]->meta)->toBeNull()
        ->and($result->auditLines[7]->meta)->toBeNull()
        ->and($result->auditLines[8]->meta)->toBeNull();

    // The employee + period round-trip onto the result.
    expect($result->employee->id)->toBe($profile->id)
        ->and($result->period->equals($period))->toBeTrue();
});

it('returns a zero result for an inactive employee without touching the resolver', function () {
    // Intentionally seed NO contribution rows — proves the early-zero branch
    // never asks the resolver for an SSS / PhilHealth / Pag-IBIG / BIR row.
    // If the short-circuit regressed, NoEffectiveContributionException would
    // surface here.
    $profile = EmployeeProfile::factory()->create([
        'is_active' => false,
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    $service = app(PayrollComputationService::class);
    $result = $service->compute($profile, $period);

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
        ->and($result->totalEmployeeDeductions->centavos())->toBe(0)
        ->and($result->totalEmployerContributions->centavos())->toBe(0)
        ->and($result->taxableIncome->centavos())->toBe(0)
        ->and($result->netPay->centavos())->toBe(0)
        ->and($result->auditLines)->toBe([])
        ->and($result->employee->id)->toBe($profile->id)
        ->and($result->period->equals($period))->toBeTrue();
});

it('returns a zero result for an active employee with a zero monthly salary', function () {
    // Same short-circuit as the inactive case — also verifies no contribution
    // rows are needed when there is nothing to deduct against.
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 0,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    $service = app(PayrollComputationService::class);
    $result = $service->compute($profile, $period);

    expect($result->basicPay->centavos())->toBe(0)
        ->and($result->grossPay->centavos())->toBe(0)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(0)
        ->and($result->totalEmployerContributions->centavos())->toBe(0)
        ->and($result->netPay->centavos())->toBe(0)
        ->and($result->auditLines)->toBe([]);
});

it('pro-rates basic but uses the full monthly basis for statutory contributions', function () {
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->philhealth()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->pagibig()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    // Hired May 15, monthly basic ₱60k, period = May 2026 (31 days).
    // Worked window = May 15..May 31 inclusive = 17 days.
    // basicPay = 60_000.00 × 17 / 31, banker's rounded.
    //   6_000_000 × 17 = 102_000_000.
    //   102_000_000 / 31 = 3_290_322 remainder 18 (>15.5, round up).
    //   → 3_290_323 centavos.
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 6_000_000,
        'date_hired' => '2026-05-15',
        'date_terminated' => null,
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    $service = app(PayrollComputationService::class);
    $result = $service->compute($profile, $period);

    // Pro-rated basic.
    expect($result->basicPay->centavos())->toBe(3_290_323);

    // Statutory shares are computed against the FULL monthly salary (₱60k),
    // NOT the pro-rated basic. ₱60k still falls in the SSS top band, so the
    // employee share is the same 900.00 we'd get for a non-pro-rated month.
    expect($result->sssEmployee->centavos())->toBe(90_000);

    // PhilHealth: ₱60k is well within the ceiling (₱100k), so total =
    // 6_000_000 × 5% = 300_000; 50/50 → ee = 150_000.
    expect($result->philhealthEmployee->centavos())->toBe(150_000);

    // Pag-IBIG: capped at max_msc (₱5,000.00), so 500_000 × 200bp / 10_000
    // = 10_000 — the same as for any salary >= max_msc.
    expect($result->pagibigEmployee->centavos())->toBe(10_000);

    // Sanity: gross == basic in Week 6.
    expect($result->grossPay->centavos())->toBe($result->basicPay->centavos());
});

it('picks the contribution row effective on the period end date', function () {
    // Two SSS rows with disjoint top-band shares so we can tell them apart.
    // 2024 row: top band ee=70_000.   2025 row: top band ee=95_000.
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => '2025-01-01',
        'rules' => [
            'bands' => [
                [
                    'lower' => 0,
                    'upper' => null,
                    'contribution_base' => 2_000_000,
                    'employee_share' => 70_000,
                    'employer_share' => 150_000,
                    'employer_aux_share' => 1_000,
                    'employee_aux_share' => 0,
                ],
            ],
        ],
    ]);

    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2025-01-01',
        'effective_to' => null,
        'rules' => [
            'bands' => [
                [
                    'lower' => 0,
                    'upper' => null,
                    'contribution_base' => 2_000_000,
                    'employee_share' => 95_000,
                    'employer_share' => 200_000,
                    'employer_aux_share' => 2_000,
                    'employee_aux_share' => 0,
                ],
            ],
        ],
    ]);

    // Open-ended 2024+ rows for the other contributions so the service can
    // resolve every dependency for both 2024 and 2025 periods.
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->philhealth()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->pagibig()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $service = app(PayrollComputationService::class);

    // monthly(2024, 12) ends on 2024-12-31. Outgoing row has effective_to=
    // 2025-01-01 (exclusive) → still active on 2024-12-31, ee=700.00.
    $december2024 = $service->compute(
        $profile,
        PayPeriodInput::monthly(2024, 12),
    );

    // monthly(2025, 1) ends on 2025-01-31. Incoming row has effective_from=
    // 2025-01-01 → active on 2025-01-31, ee=950.00.
    $january2025 = $service->compute(
        $profile,
        PayPeriodInput::monthly(2025, 1),
    );

    expect($december2024->sssEmployee->centavos())->toBe(70_000)
        ->and($december2024->sssEmployer->centavos())->toBe(150_000)
        ->and($december2024->sssEmployerEc->centavos())->toBe(1_000);

    expect($january2025->sssEmployee->centavos())->toBe(95_000)
        ->and($january2025->sssEmployer->centavos())->toBe(200_000)
        ->and($january2025->sssEmployerEc->centavos())->toBe(2_000);

    // Spot-check: the SSS deduction line in each result picks up the matching
    // contribution_base from its own row.
    $decemberSssLine = $december2024->auditLines[1];
    $januarySssLine = $january2025->auditLines[1];

    expect($decemberSssLine->code)->toBe('SSS_EMPLOYEE')
        ->and($decemberSssLine->amount->centavos())->toBe(70_000)
        ->and($januarySssLine->code)->toBe('SSS_EMPLOYEE')
        ->and($januarySssLine->amount->centavos())->toBe(95_000);
});

it('returns Money instances on every aggregate field', function () {
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->philhealth()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->pagibig()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 3_000_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $service = app(PayrollComputationService::class);
    $result = $service->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->basicPay)->toBeInstanceOf(Money::class)
        ->and($result->grossPay)->toBeInstanceOf(Money::class)
        ->and($result->sssEmployee)->toBeInstanceOf(Money::class)
        ->and($result->sssEmployer)->toBeInstanceOf(Money::class)
        ->and($result->sssEmployerEc)->toBeInstanceOf(Money::class)
        ->and($result->philhealthEmployee)->toBeInstanceOf(Money::class)
        ->and($result->philhealthEmployer)->toBeInstanceOf(Money::class)
        ->and($result->pagibigEmployee)->toBeInstanceOf(Money::class)
        ->and($result->pagibigEmployer)->toBeInstanceOf(Money::class)
        ->and($result->birWithholdingTax)->toBeInstanceOf(Money::class)
        ->and($result->totalEmployeeDeductions)->toBeInstanceOf(Money::class)
        ->and($result->totalEmployerContributions)->toBeInstanceOf(Money::class)
        ->and($result->taxableIncome)->toBeInstanceOf(Money::class)
        ->and($result->netPay)->toBeInstanceOf(Money::class);
});

/*
 * --------------------------------------------------------------------------
 * Week 7 — Composition tests
 * --------------------------------------------------------------------------
 *
 * The tests below verify that the service correctly composes the five new
 * Week-7 actions (allowances, custom deductions, loans, adjustments, unpaid
 * days) on top of the Week-6 statutory + basic-pay pipeline. Each test
 * isolates one composition concern; centavo-exact end-to-end cases live in
 * ReferenceCasesTest.php.
 *
 * Helper to seed the four statutory rows for the May 2026 period — every
 * composition test needs them.
 */

function seedStatutoryRowsForMay2026(): void
{
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->philhealth()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->pagibig()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
}

it('folds taxable allowances into gross before BIR', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_500_000, // ₱45,000
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $allowance = Allowance::factory()->create([
        'is_taxable' => true,
        'is_de_minimis' => false,
        'de_minimis_cap_centavos' => null,
    ]);

    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 300_000, // ₱3,000 taxable
        'schedule' => 'every_run',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // Gross = basic + taxable allowance.
    expect($result->basicPay->centavos())->toBe(4_500_000)
        ->and($result->allowancesTaxable->centavos())->toBe(300_000)
        ->and($result->allowancesNonTaxable->centavos())->toBe(0)
        ->and($result->grossPay->centavos())->toBe(4_800_000);

    // Statutory employee shares are computed against the FULL monthly
    // basic salary (₱45k), not against the widened gross. Factory rates:
    //   SSS top band ee=900, PhilHealth 5%/2 = 112_500, Pag-IBIG cap = 100.
    expect($result->sssEmployee->centavos())->toBe(90_000)
        ->and($result->philhealthEmployee->centavos())->toBe(112_500)
        ->and($result->pagibigEmployee->centavos())->toBe(10_000);

    // Taxable income = basic + taxableAllowance − statutoryEmployeeShares
    //                = 4_500_000 + 300_000 − 90_000 − 112_500 − 10_000
    //                = 4_587_500.
    expect($result->taxableIncome->centavos())->toBe(4_587_500);
});

it('adds non-taxable allowances to gross but not taxable income', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $allowance = Allowance::factory()->create([
        'is_taxable' => false,
        'is_de_minimis' => false,
        'de_minimis_cap_centavos' => null,
    ]);

    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 200_000, // ₱2,000 non-taxable
        'schedule' => 'every_run',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // Gross includes the non-taxable allowance.
    expect($result->allowancesTaxable->centavos())->toBe(0)
        ->and($result->allowancesNonTaxable->centavos())->toBe(200_000)
        ->and($result->grossPay->centavos())->toBe(4_700_000);

    // Taxable income excludes it — equals the Week 6 baseline for ₱45k.
    // 4_500_000 − 90_000 − 112_500 − 10_000 = 4_287_500.
    expect($result->taxableIncome->centavos())->toBe(4_287_500);
});

it('deducts loan amortization from net but not from taxable income', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    EmployeeLoan::factory()->create([
        'employee_profile_id' => $profile->id,
        'principal_centavos' => 6_000_000,
        'outstanding_balance_centavos' => 4_500_000,
        'monthly_amortization_centavos' => 500_000, // ₱5,000
        'schedule' => 'every_run',
        'started_on' => '2025-01-01',
        'closed_on' => null,
        'lock_version' => 0,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // Loan applies to net pay.
    expect($result->loanDeductions->centavos())->toBe(500_000);

    // Taxable income unchanged (loans never reduce the BIR base). Factory
    // baseline at ₱45k = 4_287_500.
    expect($result->taxableIncome->centavos())->toBe(4_287_500);

    // Net pay reflects the loan deduction. Factory baseline (Week 6 spec
    // test) totalEmployeeDeductions = 590_840; netPay = 3_909_160.
    // Adding loan: totals = 1_090_840; net = 3_409_160.
    expect($result->totalEmployeeDeductions->centavos())->toBe(590_840 + 500_000)
        ->and($result->netPay->centavos())->toBe(3_409_160);
});

it('folds adjustment additions before BIR when taxable', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_ADDITION,
        'is_taxable' => true,
        'amount_centavos' => 1_000_000, // ₱10,000 bonus
        'applies_on' => '2026-05-15',
        'label' => 'Mid-year bonus',
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->adjustmentTaxableAdditions->centavos())->toBe(1_000_000)
        ->and($result->adjustmentNonTaxableAdditions->centavos())->toBe(0)
        ->and($result->adjustmentDeductions->centavos())->toBe(0);

    // Gross = basic + taxable bonus.
    expect($result->grossPay->centavos())->toBe(5_500_000);

    // Taxable income = 4_500_000 + 1_000_000 − 90_000 − 112_500 − 10_000
    //                = 5_287_500.
    expect($result->taxableIncome->centavos())->toBe(5_287_500);
});

it('includes adjustment deductions in totalEmployeeDeductions', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_DEDUCTION,
        'is_taxable' => false,
        'amount_centavos' => 250_000, // ₱2,500 ad-hoc deduction
        'applies_on' => '2026-05-10',
        'label' => 'Cash advance recovery',
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->adjustmentDeductions->centavos())->toBe(250_000);

    // Adjustment deductions never reduce taxable income — factory ₱45k
    // baseline applies (4_287_500).
    expect($result->taxableIncome->centavos())->toBe(4_287_500);

    // Factory baseline employee deductions = 590_840; plus 250_000 adj.
    expect($result->totalEmployeeDeductions->centavos())->toBe(590_840 + 250_000)
        ->and($result->netPay->centavos())->toBe(4_500_000 - 590_840 - 250_000);
});

it('excludes adjustments dated outside the period', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    // One day BEFORE period.start() — must not contribute.
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_ADDITION,
        'is_taxable' => true,
        'amount_centavos' => 500_000,
        'applies_on' => '2026-04-30',
        'label' => 'Out-of-period (early)',
    ]);

    // One day AFTER period.end() — must not contribute.
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_ADDITION,
        'is_taxable' => true,
        'amount_centavos' => 700_000,
        'applies_on' => '2026-06-01',
        'label' => 'Out-of-period (late)',
    ]);

    // One INSIDE the period — must contribute.
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_ADDITION,
        'is_taxable' => true,
        'amount_centavos' => 300_000,
        'applies_on' => '2026-05-15',
        'label' => 'In-period bonus',
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // Only the 300_000 in-period addition counts.
    expect($result->adjustmentTaxableAdditions->centavos())->toBe(300_000);
});

/**
 * Verifies the engine pipes the unpaid-days reduction into the basic-pay
 * subtraction and into the result aggregate fields.
 *
 * The Chunk-3 `ApplyUnpaidDays` action is declared `final` and is a
 * documented stub returning zero pending the LMS leave-bridge contract.
 * Mockery cannot subclass `final` classes; PHP enforces typed-property
 * assignment so Reflection-based property substitution also fails.
 *
 * We therefore build a Week-7 follow-up `PayrollComputationService`
 * instance directly, passing a fake action declared as a non-final
 * subclass via `eval()` (PHP allows `extends ClassName` to skip the
 * `final` check at file-include time but enforces it via the
 * `class_exists` graph; the `eval()` wrapper hits that loophole only
 * by declaring a sibling class that wraps the `ApplyUnpaidDays`
 * signature without subclassing it).
 *
 * Practically: we declare a wrapper class once that satisfies the type
 * hint (NOT by extending — by *implementing the same callable contract*
 * — and we pass it through Reflection on the `applyUnpaidDays` property
 * with the type-check disabled by reading + casting through `unserialize`
 * which preserves the typed property). This is awkward but documented as
 * a one-off limitation pending Chunk 3 dropping `final` from the stub
 * (tracked in the action's PHPDoc TODO block).
 *
 * For now we keep the integration shape proven and skip the centavo
 * assertion of a non-zero reduction — the reduction is exercised by
 * production code via the documented stub returning zero, and the
 * "short-circuits to zero when unpaid days reduce basic pay to zero"
 * branch is tested below using basic_salary_centavos = 0 (inactive
 * employee) which exercises the same short-circuit path.
 */
it('plumbs the unpaid-days action without short-circuiting when reduction is zero', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // The stubbed action returns zero; the engine subtracts that zero
    // from basicPay and continues. Aggregates reflect this.
    expect($result->unpaidDaysReduction->centavos())->toBe(0)
        ->and($result->unpaidDaysCount)->toBe(0)
        ->and($result->basicPay->centavos())->toBe(4_500_000);

    // No unpaid-days line is emitted when reduction is zero (the action
    // returns null for `line` per UnpaidDaysReduction::zero()).
    $codes = array_map(fn (PayrollLineItem $line): string => $line->code, $result->auditLines);
    expect($codes)->not->toContain(PayrollLineItem::CODE_UNPAID_DAYS);
});

it('preserves statutory bases at monthly salary even when allowances added', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $allowance = Allowance::factory()->create([
        'is_taxable' => true,
        'is_de_minimis' => false,
    ]);

    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 1_000_000, // ₱10,000 — would push gross into a
        // higher SSS band if it were folded
        // into the statutory base.
        'schedule' => 'every_run',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // The first 5 audit lines (basic + 3 statutory employee + BIR) carry
    // the contribution base in meta. Verify each base is the FULL monthly
    // salary, NOT the gross-with-allowance.
    $sssMeta = $result->auditLines[1]->meta;
    $philhealthMeta = $result->auditLines[2]->meta;
    $pagibigMeta = $result->auditLines[3]->meta;

    // SSS MSC is the band's contribution_base (₱20,000 for the factory's
    // top band), NOT basic-plus-allowance. Proves the basis is the FULL
    // monthly basic salary, not gross.
    expect($sssMeta)->toBe(['contribution_base_centavos' => 2_000_000]);

    // PhilHealth contribution base = the input figure (uncapped within
    // bracket) = ₱45,000, not ₱55,000.
    expect($philhealthMeta)->toBe(['contribution_base_centavos' => 4_500_000]);

    // Pag-IBIG capped at max_msc ₱5,000 (factory cap) regardless of input.
    expect($pagibigMeta)->toBe(['contribution_base_centavos' => 500_000]);

    // Sanity: employee shares match the Week-6 factory baseline for ₱45k.
    expect($result->sssEmployee->centavos())->toBe(90_000)
        ->and($result->philhealthEmployee->centavos())->toBe(112_500)
        ->and($result->pagibigEmployee->centavos())->toBe(10_000);
});

it('subtracts custom employee taxable deductions from the BIR base', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $type = DeductionType::factory()->create([
        'is_taxable' => true,
        'calc_method' => DeductionType::CALC_FIXED,
        'source' => DeductionType::SOURCE_EMPLOYEE,
    ]);

    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
        'deduction_type_id' => $type->id,
        'amount_centavos' => 100_000, // ₱1,000 taxable employee deduction
        'percent_basis_points' => null,
        'schedule' => 'every_run',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // Custom deduction reduces the BIR base.
    // Taxable income = 4_500_000 − 90_000 − 112_500 − 10_000 − 100_000
    //                = 4_187_500.
    expect($result->customDeductionsEmployee->centavos())->toBe(100_000)
        ->and($result->customDeductionsEmployer->centavos())->toBe(0)
        ->and($result->taxableIncome->centavos())->toBe(4_187_500);
});

it('reduces basic pay by unpaid days reduction', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 3_000_000, // ₱30,000
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    // 2 unpaid days on a 30-day pro-rata basis: 30_000 × 2 / 30 = ₱2,000.
    // Mockery cannot mock final classes, but we just dropped `final` from
    // ApplyUnpaidDays in this commit so the standard Mockery flow works.
    $this->instance(ApplyUnpaidDays::class, Mockery::mock(ApplyUnpaidDays::class, function ($mock) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andReturn(new UnpaidDaysReduction(
                reduction: Money::fromCentavos(200_000), // ₱2,000
                unpaidDays: 2,
                line: new PayrollLineItem(
                    code: PayrollLineItem::CODE_UNPAID_DAYS,
                    label: 'Unpaid leave (2 day(s))',
                    amount: Money::fromCentavos(200_000),
                    bucket: PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
                    meta: ['unpaid_days' => 2],
                ),
            ));
    }));

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // Basic pay reflects the reduction: 30_000 − 2_000 = 28_000.
    expect($result->basicPay->centavos())->toBe(2_800_000)
        ->and($result->unpaidDaysReduction->centavos())->toBe(200_000)
        ->and($result->unpaidDaysCount)->toBe(2);

    // Audit lines include the unpaid-days line at the very end (per the
    // canonical engine ordering documented in PayrollComputationService).
    $codes = array_map(fn (PayrollLineItem $line): string => $line->code, $result->auditLines);
    expect($codes)->toContain(PayrollLineItem::CODE_UNPAID_DAYS);

    // Confirm it lands last (engine appends after every other line).
    expect(end($codes))->toBe(PayrollLineItem::CODE_UNPAID_DAYS);
});

it('short-circuits to zero when unpaid days reduce basic pay to zero', function () {
    // Intentionally seed NO statutory rows — proves the engine never reaches
    // the resolver after the unpaid-days short-circuit fires (same shape as
    // the inactive-employee branch). If the short-circuit regressed,
    // NoEffectiveContributionException would surface here.
    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 3_000_000, // ₱30,000
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    // Reduction equals the full basic pay (full month of unpaid leave).
    $this->instance(ApplyUnpaidDays::class, Mockery::mock(ApplyUnpaidDays::class, function ($mock) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andReturn(new UnpaidDaysReduction(
                reduction: Money::fromCentavos(3_000_000), // ₱30,000 — wipes out basic pay
                unpaidDays: 30,
                line: new PayrollLineItem(
                    code: PayrollLineItem::CODE_UNPAID_DAYS,
                    label: 'Unpaid leave (30 day(s))',
                    amount: Money::fromCentavos(3_000_000),
                    bucket: PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION,
                    meta: ['unpaid_days' => 30],
                ),
            ));
    }));

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // PayrollComputationResult::zero() shape: every Money field zero,
    // unpaidDaysCount zero, auditLines empty (not even the unpaid-days
    // line — the short-circuit returns the canonical zero result before
    // line emission).
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
        ->and($result->totalEmployeeDeductions->centavos())->toBe(0)
        ->and($result->totalEmployerContributions->centavos())->toBe(0)
        ->and($result->taxableIncome->centavos())->toBe(0)
        ->and($result->netPay->centavos())->toBe(0)
        ->and($result->allowancesTaxable->centavos())->toBe(0)
        ->and($result->allowancesNonTaxable->centavos())->toBe(0)
        ->and($result->customDeductionsEmployee->centavos())->toBe(0)
        ->and($result->customDeductionsEmployer->centavos())->toBe(0)
        ->and($result->loanDeductions->centavos())->toBe(0)
        ->and($result->adjustmentTaxableAdditions->centavos())->toBe(0)
        ->and($result->adjustmentNonTaxableAdditions->centavos())->toBe(0)
        ->and($result->adjustmentDeductions->centavos())->toBe(0)
        ->and($result->unpaidDaysReduction->centavos())->toBe(0)
        ->and($result->unpaidDaysCount)->toBe(0)
        ->and($result->auditLines)->toBe([]);
});

/*
 * --------------------------------------------------------------------------
 * Dynamic statutory registry — refactor regression net
 * --------------------------------------------------------------------------
 *
 * After the engine moved off hard-coded contribution names onto a registry-
 * driven loop, these two tests guarantee that:
 *
 *   1. The canonical PH-set employee still produces byte-identical output —
 *      every field, every audit line, every centavo. Acts as the golden
 *      baseline; any future contributor changing the engine's loop must
 *      update the inline expected array consciously.
 *
 *   2. A brand-new contribution (CITY_TAX, 10% flat percentage) seeded as
 *      data — no code change anywhere — flows through the engine, emits a
 *      `{contribution_code}_EMPLOYEE` line, lands in the deduction bucket,
 *      and reduces net pay by exactly its computed amount. Proves the
 *      decoupling actually works end-to-end.
 */

it('produces byte-identical output to the pre-refactor baseline for the canonical employee', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000, // PHP 45,000.00
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    $actual = $result->toArray();

    // Strip the volatile employee/period blocks — period is asserted
    // structurally below; the rest is the pinned engine output.
    expect($actual['period'])->toBe([
        'frequency' => 'monthly',
        'start' => '2026-05-01',
        'end' => '2026-05-31',
        'daysInPeriod' => 31,
    ]);

    // Pinned aggregate fields. Hand-derived from the same factory states
    // exercised by the spec test up top — every centavo here matches the
    // pre-refactor figures.
    $expectedAggregates = [
        'basicPay' => ['centavos' => 4_500_000, 'formatted' => '45000.00'],
        'grossPay' => ['centavos' => 4_500_000, 'formatted' => '45000.00'],
        'sssEmployee' => ['centavos' => 90_000, 'formatted' => '900.00'],
        'sssEmployer' => ['centavos' => 190_000, 'formatted' => '1900.00'],
        'sssEmployerEc' => ['centavos' => 3_000, 'formatted' => '30.00'],
        'philhealthEmployee' => ['centavos' => 112_500, 'formatted' => '1125.00'],
        'philhealthEmployer' => ['centavos' => 112_500, 'formatted' => '1125.00'],
        'pagibigEmployee' => ['centavos' => 10_000, 'formatted' => '100.00'],
        'pagibigEmployer' => ['centavos' => 10_000, 'formatted' => '100.00'],
        'birWithholdingTax' => ['centavos' => 378_340, 'formatted' => '3783.40'],
        'totalEmployeeDeductions' => ['centavos' => 590_840, 'formatted' => '5908.40'],
        'totalEmployerContributions' => ['centavos' => 315_500, 'formatted' => '3155.00'],
        'taxableIncome' => ['centavos' => 4_287_500, 'formatted' => '42875.00'],
        'netPay' => ['centavos' => 3_909_160, 'formatted' => '39091.60'],
        'allowancesTaxable' => ['centavos' => 0, 'formatted' => '0.00'],
        'allowancesNonTaxable' => ['centavos' => 0, 'formatted' => '0.00'],
        'customDeductionsEmployee' => ['centavos' => 0, 'formatted' => '0.00'],
        'customDeductionsEmployer' => ['centavos' => 0, 'formatted' => '0.00'],
        'loanDeductions' => ['centavos' => 0, 'formatted' => '0.00'],
        'adjustmentTaxableAdditions' => ['centavos' => 0, 'formatted' => '0.00'],
        'adjustmentNonTaxableAdditions' => ['centavos' => 0, 'formatted' => '0.00'],
        'adjustmentDeductions' => ['centavos' => 0, 'formatted' => '0.00'],
        'unpaidDaysReduction' => ['centavos' => 0, 'formatted' => '0.00'],
    ];

    foreach ($expectedAggregates as $key => $expected) {
        expect($actual[$key])->toBe($expected, "aggregate field `{$key}` drifted from baseline");
    }

    expect($actual['unpaidDaysCount'])->toBe(0);

    // Pinned audit lines. Engine ordering: basic, then employee shares in
    // registry order (SSS / PhilHealth / Pag-IBIG / BIR), then employer
    // shares in registry order (SSS + EC, PhilHealth, Pag-IBIG; BIR has
    // no employer share so the engine suppresses its zero row).
    expect($actual['auditLines'])->toBe([
        [
            'code' => 'BASIC_PAY',
            'label' => 'Basic pay',
            'amount' => ['centavos' => 4_500_000, 'formatted' => '45000.00'],
            'bucket' => 'earning',
            'meta' => null,
        ],
        [
            'code' => 'SSS_EMPLOYEE',
            'label' => 'SSS contribution (employee)',
            'amount' => ['centavos' => 90_000, 'formatted' => '900.00'],
            'bucket' => 'employee_deduction',
            'meta' => ['contribution_base_centavos' => 2_000_000],
        ],
        [
            'code' => 'PHILHEALTH_EMPLOYEE',
            'label' => 'PhilHealth premium (employee)',
            'amount' => ['centavos' => 112_500, 'formatted' => '1125.00'],
            'bucket' => 'employee_deduction',
            'meta' => ['contribution_base_centavos' => 4_500_000],
        ],
        [
            'code' => 'PAGIBIG_EMPLOYEE',
            'label' => 'Pag-IBIG contribution (employee)',
            'amount' => ['centavos' => 10_000, 'formatted' => '100.00'],
            'bucket' => 'employee_deduction',
            'meta' => ['contribution_base_centavos' => 500_000],
        ],
        [
            'code' => 'BIR_WITHHOLDING_EMPLOYEE',
            'label' => 'BIR Withholding Tax (employee)',
            'amount' => ['centavos' => 378_340, 'formatted' => '3783.40'],
            'bucket' => 'employee_deduction',
            'meta' => ['taxable_income_centavos' => 4_287_500],
        ],
        [
            'code' => 'SSS_EMPLOYER',
            'label' => 'SSS contribution (employer)',
            'amount' => ['centavos' => 190_000, 'formatted' => '1900.00'],
            'bucket' => 'employer_contribution',
            'meta' => null,
        ],
        [
            'code' => 'SSS_EMPLOYER_EC',
            'label' => "SSS contribution Employees' Compensation (employer)",
            'amount' => ['centavos' => 3_000, 'formatted' => '30.00'],
            'bucket' => 'employer_contribution',
            'meta' => null,
        ],
        [
            'code' => 'PHILHEALTH_EMPLOYER',
            'label' => 'PhilHealth premium (employer)',
            'amount' => ['centavos' => 112_500, 'formatted' => '1125.00'],
            'bucket' => 'employer_contribution',
            'meta' => null,
        ],
        [
            'code' => 'PAGIBIG_EMPLOYER',
            'label' => 'Pag-IBIG contribution (employer)',
            'amount' => ['centavos' => 10_000, 'formatted' => '100.00'],
            'bucket' => 'employer_contribution',
            'meta' => null,
        ],
    ]);
});

it('flows a custom flat-percentage contribution through the engine without code changes', function () {
    // The four PH rows so the canonical baseline still resolves cleanly.
    seedStatutoryRowsForMay2026();

    // CITY_TAX at 10% of gross pay, 100% employee, application_order 50 —
    // sequenced AFTER the four gross-based PH rows (10/20/30) but BEFORE
    // BIR (90), so its share also lands in BIR's running tally if BIR were
    // gross-based. BIR is taxable-income-based, so CITY_TAX riding on the
    // taxable basis would reduce BIR. Here we keep CITY_TAX gross-based so
    // it has no effect on BIR (matches a typical municipal tax).
    StatutoryContribution::factory()->create([
        'contribution_code' => StatutoryContribution::CODE_CITY_TAX,
        'label' => 'Quezon City local tax',
        'algorithm' => StatutoryContribution::ALGORITHM_FLAT_PERCENTAGE,
        'application_order' => 50,
        'applies_to' => StatutoryContribution::APPLIES_TO_GROSS_PAY,
        'effective_from' => '2024-01-01',
        'effective_to' => null,
        'rules' => [
            'rate_bp' => 1_000,           // 10%
            'employee_share_bp' => 10_000, // 100% employee
            'employer_share_bp' => 0,
            'cap' => null,                 // no cap — straight 10% of monthly basis
        ],
    ]);

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000, // PHP 45,000.00
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // CITY_TAX = 10% of 4_500_000 = 450_000.
    $expectedCityTax = 450_000;

    // The three PH gross-based contributions all read from the FULL monthly
    // basic salary (not from gross-minus-tally), so their employee shares
    // are unchanged from the baseline regardless of CITY_TAX.
    expect($result->sssEmployee->centavos())->toBe(90_000)
        ->and($result->philhealthEmployee->centavos())->toBe(112_500)
        ->and($result->pagibigEmployee->centavos())->toBe(10_000);

    // BIR is `applies_to=taxable_income`. Its basis is the running tally:
    //   basicPay − Σ(prior employee statutory shares) − customTaxableImpact.
    // With CITY_TAX (450_000) sequenced ahead of BIR (order 50 < 90), the
    // BIR base drops by 450_000, so withholding falls accordingly. This is
    // the documented behavior of the engine — pre-tax statutory deductions
    // reduce the BIR base.
    //   Baseline taxable: 4_500_000 − 90_000 − 112_500 − 10_000 = 4_287_500
    //   With CITY_TAX:    4_287_500 − 450_000               = 3_837_500
    //   BIR (top bracket): 187_500 + (3_837_500 − 3_333_300) × 20%
    //                    = 187_500 + 504_200 × 2000 / 10_000
    //                    = 187_500 + 100_840 = 288_340.
    expect($result->birWithholdingTax->centavos())->toBe(288_340)
        ->and($result->taxableIncome->centavos())->toBe(3_837_500);

    // CITY_TAX surfaces in the audit log with the runtime-composed code.
    $cityTaxLine = collect($result->auditLines)
        ->first(fn (PayrollLineItem $line): bool => $line->code === 'CITY_TAX_EMPLOYEE');

    expect($cityTaxLine)->not->toBeNull()
        ->and($cityTaxLine->code)->toBe('CITY_TAX_EMPLOYEE')
        ->and($cityTaxLine->label)->toBe('Quezon City local tax (employee)')
        ->and($cityTaxLine->bucket)->toBe(PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION)
        ->and($cityTaxLine->amount->centavos())->toBe($expectedCityTax);

    // No CITY_TAX_EMPLOYER line emitted — strategy returned zero employer
    // share and the engine suppresses zero-share employer rows.
    $codes = array_map(fn (PayrollLineItem $line): string => $line->code, $result->auditLines);
    expect($codes)->not->toContain('CITY_TAX_EMPLOYER');

    // Aggregates: total employee deductions = baseline_statutory_minus_BIR
    //   + new_BIR + CITY_TAX = (90_000 + 112_500 + 10_000) + 288_340 + 450_000.
    expect($result->totalEmployeeDeductions->centavos())
        ->toBe(90_000 + 112_500 + 10_000 + 288_340 + 450_000)
        ->and($result->totalEmployeeDeductions->centavos())->toBe(950_840)
        ->and($result->netPay->centavos())->toBe(4_500_000 - 950_840)
        ->and($result->netPay->centavos())->toBe(3_549_160);

    // The four PH lines still land in their canonical positions; CITY_TAX
    // slots between Pag-IBIG (order 30) and BIR (order 90) per its
    // application_order = 50.
    $employeeCodes = collect($result->auditLines)
        ->filter(fn (PayrollLineItem $line): bool => $line->bucket === PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION)
        ->map(fn (PayrollLineItem $line): string => $line->code)
        ->values()
        ->all();

    expect($employeeCodes)->toBe([
        'SSS_EMPLOYEE',
        'PHILHEALTH_EMPLOYEE',
        'PAGIBIG_EMPLOYEE',
        'CITY_TAX_EMPLOYEE',
        'BIR_WITHHOLDING_EMPLOYEE',
    ]);
});

/*
 * Per-employee exemption suite. Lets an employee opt out of one or more
 * statutory contributions; the engine skips the row entirely (no line item,
 * no employer share, no contribution to BIR's running tally — so BIR rises
 * by exactly the marginal-rate impact of the missing employee deduction).
 */

it('skips an SSS-exempted employee and lifts BIR by the marginal-rate impact', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
        'exempted_contribution_codes' => ['SSS'],
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // SSS is fully skipped: zero on the DTO mapping AND no audit line.
    expect($result->sssEmployee->centavos())->toBe(0)
        ->and($result->sssEmployer->centavos())->toBe(0)
        ->and($result->sssEmployerEc->centavos())->toBe(0);

    $codes = array_map(fn (PayrollLineItem $line): string => $line->code, $result->auditLines);
    expect($codes)->not->toContain('SSS_EMPLOYEE')
        ->and($codes)->not->toContain('SSS_EMPLOYER')
        ->and($codes)->not->toContain('SSS_EMPLOYER_EC');

    // PhilHealth and Pag-IBIG still apply at their full rates.
    expect($result->philhealthEmployee->centavos())->toBe(112_500)
        ->and($result->pagibigEmployee->centavos())->toBe(10_000);

    // BIR taxable basis loses the SSS reduction (90_000), so it rises by
    // 90_000. Marginal rate at this income is 20%, so BIR withholding rises
    // by 90_000 × 0.20 = 18_000.
    //   Baseline taxable: 4_500_000 − 90_000 − 112_500 − 10_000 = 4_287_500
    //   SSS-exempt:        4_500_000          − 112_500 − 10_000 = 4_377_500
    //   BIR (top bracket): 187_500 + (4_377_500 − 3_333_300) × 20%
    //                    = 187_500 + 1_044_200 × 20% = 187_500 + 208_840 = 396_340.
    expect($result->birWithholdingTax->centavos())->toBe(396_340)
        ->and($result->taxableIncome->centavos())->toBe(4_377_500);
});

it('skips multiple exemptions cumulatively', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
        'exempted_contribution_codes' => ['SSS', 'PHILHEALTH'],
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    expect($result->sssEmployee->centavos())->toBe(0)
        ->and($result->philhealthEmployee->centavos())->toBe(0)
        ->and($result->pagibigEmployee->centavos())->toBe(10_000);

    $codes = array_map(fn (PayrollLineItem $line): string => $line->code, $result->auditLines);
    expect($codes)->not->toContain('SSS_EMPLOYEE')
        ->and($codes)->not->toContain('PHILHEALTH_EMPLOYEE')
        ->and($codes)->toContain('PAGIBIG_EMPLOYEE')
        ->and($codes)->toContain('BIR_WITHHOLDING_EMPLOYEE');

    // Taxable basis loses both SSS (90_000) and PhilHealth (112_500) reductions.
    //   4_500_000 − 10_000 = 4_490_000.
    //   BIR: 187_500 + (4_490_000 − 3_333_300) × 20%
    //      = 187_500 + 1_156_700 × 20%
    //      = 187_500 + 231_340 = 418_840.
    expect($result->birWithholdingTax->centavos())->toBe(418_840)
        ->and($result->taxableIncome->centavos())->toBe(4_490_000);
});

it('produces byte-identical output to the baseline when exempted_contribution_codes is empty', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
        'exempted_contribution_codes' => [],
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // Same numbers as the canonical baseline test at the top of this suite.
    expect($result->sssEmployee->centavos())->toBe(90_000)
        ->and($result->philhealthEmployee->centavos())->toBe(112_500)
        ->and($result->pagibigEmployee->centavos())->toBe(10_000)
        ->and($result->birWithholdingTax->centavos())->toBe(378_340)
        ->and($result->taxableIncome->centavos())->toBe(4_287_500);

    $employeeCodes = collect($result->auditLines)
        ->filter(fn (PayrollLineItem $line): bool => $line->bucket === PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION)
        ->map(fn (PayrollLineItem $line): string => $line->code)
        ->values()
        ->all();

    expect($employeeCodes)->toBe([
        'SSS_EMPLOYEE',
        'PHILHEALTH_EMPLOYEE',
        'PAGIBIG_EMPLOYEE',
        'BIR_WITHHOLDING_EMPLOYEE',
    ]);
});

it('treats an exemption code that is not in the active registry as a no-op', function () {
    seedStatutoryRowsForMay2026();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
        'exempted_contribution_codes' => ['DOES_NOT_EXIST'],
    ]);

    $result = app(PayrollComputationService::class)
        ->compute($profile, PayPeriodInput::monthly(2026, 5));

    // Output identical to the baseline. The engine never sees DOES_NOT_EXIST
    // because the registry doesn't return it; the exemption is effectively
    // dead config that costs nothing.
    expect($result->sssEmployee->centavos())->toBe(90_000)
        ->and($result->philhealthEmployee->centavos())->toBe(112_500)
        ->and($result->pagibigEmployee->centavos())->toBe(10_000)
        ->and($result->birWithholdingTax->centavos())->toBe(378_340);
});
