<?php

declare(strict_types=1);

use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\StatutoryContribution;
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
        PayrollLineItem::CODE_SSS_EMPLOYEE,
        PayrollLineItem::CODE_PHILHEALTH_EMPLOYEE,
        PayrollLineItem::CODE_PAGIBIG_EMPLOYEE,
        PayrollLineItem::CODE_BIR_WITHHOLDING,
        PayrollLineItem::CODE_SSS_EMPLOYER,
        PayrollLineItem::CODE_SSS_EMPLOYER_EC,
        PayrollLineItem::CODE_PHILHEALTH_EMPLOYER,
        PayrollLineItem::CODE_PAGIBIG_EMPLOYER,
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

    expect($decemberSssLine->code)->toBe(PayrollLineItem::CODE_SSS_EMPLOYEE)
        ->and($decemberSssLine->amount->centavos())->toBe(70_000)
        ->and($januarySssLine->code)->toBe(PayrollLineItem::CODE_SSS_EMPLOYEE)
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
