<?php

declare(strict_types=1);

use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('belongs to a payroll run and an employee profile', function () {
    $run = PayrollRun::factory()->create();
    $profile = EmployeeProfile::factory()->create();

    $payslip = Payslip::factory()
        ->for($run, 'payrollRun')
        ->for($profile, 'employeeProfile')
        ->create();

    expect($payslip->payrollRun->id)->toBe($run->id)
        ->and($payslip->employeeProfile->id)->toBe($profile->id);
});

it('exposes Money helpers for every centavos column', function () {
    $payslip = Payslip::factory()->create([
        'gross_pay_centavos' => 4_500_000,
        'total_employee_deductions_centavos' => 590_840,
        'total_employer_contributions_centavos' => 480_000,
        'net_pay_centavos' => 3_909_160,
        'taxable_income_centavos' => 4_287_500,
    ]);

    expect($payslip->grossPay())->toBeInstanceOf(Money::class)
        ->and($payslip->grossPay()->centavos())->toBe(4_500_000)
        ->and($payslip->totalEmployeeDeductions()->centavos())->toBe(590_840)
        ->and($payslip->totalEmployerContributions()->centavos())->toBe(480_000)
        ->and($payslip->netPay()->centavos())->toBe(3_909_160)
        ->and($payslip->taxableIncome()->centavos())->toBe(4_287_500);
});

it('hydrates audit_lines back into PayrollLineItem instances', function () {
    $payslip = Payslip::factory()->create();

    $items = $payslip->hydratedAuditLines();

    expect($items)->toBeArray()
        ->and($items[0])->toBeInstanceOf(PayrollLineItem::class)
        ->and($items[0]->code)->toBe(PayrollLineItem::CODE_BASIC_PAY)
        ->and($items[0]->amount)->toBeInstanceOf(Money::class)
        ->and($items[0]->amount->centavos())->toBe(4_500_000)
        ->and($items[1]->code)->toBe('SSS_EMPLOYEE')
        ->and($items[1]->bucket)->toBe(PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION);
});

it('round-trips applied_exemptions as an array', function () {
    $payslip = Payslip::factory()->create([
        'applied_exemptions' => ['SSS', 'PHILHEALTH'],
    ]);
    $payslip->refresh();

    expect($payslip->applied_exemptions)->toBe(['SSS', 'PHILHEALTH']);
});

it('enforces unique (payroll_run_id, employee_profile_id)', function () {
    $run = PayrollRun::factory()->create();
    $profile = EmployeeProfile::factory()->create();

    Payslip::factory()->for($run, 'payrollRun')->for($profile, 'employeeProfile')->create();

    expect(fn () => Payslip::factory()
        ->for($run, 'payrollRun')
        ->for($profile, 'employeeProfile')
        ->create(),
    )->toThrow(Exception::class);
});
