<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;

/**
 * What the payslip document says.
 *
 * The route tests assert only that the response is a PDF, which a template
 * that had lost half its content would still satisfy. These render the Blade
 * to HTML and read it, so the wording an employee relies on is pinned.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function payslipViewModel(array $overrides = []): array
{
    return array_replace_recursive([
        'run' => [
            'id' => 13,
            'status' => 'posted',
            'pay_period' => [
                'code' => 'JULY-1-30-2',
                'start_date' => '2026-07-01',
                'end_date' => '2026-07-30',
            ],
        ],
        'payslip' => [
            'id' => 171,
            'lms_staff_id' => 16,
            'gross_pay_centavos' => 4_800_000,
            'total_employee_deductions_centavos' => 732_840,
            'total_employer_contributions_centavos' => 458_000,
            'net_pay_centavos' => 4_067_160,
            'taxable_income_centavos' => 4_485_000,
            'applied_exemptions' => [],
            'computed_at_formatted' => 'July 13, 2026',
        ],
        'employee' => [
            'lms_staff_id' => 16,
            'staff_no' => '16',
            'full_name' => 'Trixy Ann Tamundong',
            'email' => 'trixy@example.test',
            'tin' => null,
            'sss_number' => null,
            'philhealth_number' => null,
            'pagibig_number' => null,
        ],
        'earnings' => [
            ['code' => 'BASIC_PAY', 'label' => 'Basic pay', 'amount' => 4_800_000, 'bucket' => 'earning'],
        ],
        'deductions' => [
            ['code' => 'SSS_EMPLOYEE', 'label' => 'SSS Contribution (2025) (employee)', 'amount' => 175_000, 'bucket' => 'employee_deduction'],
            ['code' => 'BIR_WITHHOLDING_EMPLOYEE', 'label' => 'BIR Withholding Tax (TRAIN-law, 2023+) (employee)', 'amount' => 417_840, 'bucket' => 'employee_deduction'],
        ],
        'employer_lines' => [
            ['code' => 'SSS_EMPLOYER', 'label' => 'SSS Contribution (2025) (employer)', 'amount' => 315_000, 'bucket' => 'employer_contribution'],
        ],
        'school' => [
            'name' => 'Mindhearts Montessori School',
            'tin' => '009-123-456-000',
            'address' => 'Gen. Trias Drive, Dasmariñas, Cavite',
            'logo' => null,
        ],
    ], $overrides);
}

function renderPayslip(array $overrides = []): string
{
    return View::make('payslips.pdf', payslipViewModel($overrides))->render();
}

it('names the employer, so the document can stand as proof of employment', function () {
    $html = renderPayslip();

    expect($html)
        ->toContain('Mindhearts Montessori School')
        ->toContain('009-123-456-000')
        ->toContain('Gen. Trias Drive');
});

it('says employer contributions were not taken from the employee', function () {
    // The sentence this document exists to carry. Without it, the employer
    // block reads as a second set of deductions.
    expect(renderPayslip())
        ->toContain('Paid for you, on top of your pay')
        ->toContain('not taken from your pay');
});

it('shows what each agency was credited, employee and employer together', function () {
    $html = renderPayslip();

    expect($html)
        ->toContain('Credited to your record this period')
        // 1,750.00 withheld + 3,150.00 paid by the school.
        ->toContain('₱4,900.00');
});

it('keeps withholding tax out of the credited figure', function () {
    // Tax is remitted but buys no entitlement, so it must not inflate what
    // the employee believes an agency holds for them.
    expect(renderPayslip())->not->toContain('₱9,078.40');
});

it('does not print machine codes at an employee', function () {
    $html = renderPayslip();

    expect($html)
        ->not->toContain('BASIC_PAY')
        ->not->toContain('SSS_EMPLOYEE')
        ->not->toContain('BIR_WITHHOLDING_EMPLOYEE');
});

it('drops the redundant (employee) and (employer) suffixes from labels', function () {
    $html = renderPayslip();

    expect($html)
        ->toContain('SSS Contribution (2025)')
        ->not->toContain('(employee)')
        ->not->toContain('(employer)');
});

it('writes the period for a person rather than as two ISO dates', function () {
    expect(renderPayslip())
        ->toContain('1 – 30 July 2026')
        ->not->toContain('2026-07-01');
});

it('renders without a logo, a period, or any government number', function () {
    // The state a school is in on day one. A payslip must still print.
    $html = renderPayslip([
        'school' => ['name' => 'Mindhearts', 'tin' => null, 'address' => null, 'logo' => null],
        'run' => ['pay_period' => null],
    ]);

    expect($html)->toContain('Trixy Ann Tamundong')
        ->toContain('Run 13')
        ->toContain('₱40,671.60');
});

it('renders the logo as an embedded data URI, never a URL', function () {
    // dompdf runs with `enable_remote` off and refuses an http(s) image
    // silently, so a URL here would print nothing and say nothing.
    $html = renderPayslip([
        'school' => ['logo' => 'data:image/png;base64,AAAA'],
    ]);

    expect($html)->toContain('src="data:image/png;base64,AAAA"');
});

it('states the net pay and what it is', function () {
    expect(renderPayslip())
        ->toContain('Net pay')
        ->toContain('₱40,671.60')
        ->toContain('Taxable income ₱44,850.00');
});
