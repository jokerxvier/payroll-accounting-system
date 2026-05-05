<?php

declare(strict_types=1);

use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('belongs to a pay period', function () {
    $period = PayPeriod::factory()->monthly(2026, 5)->create();
    $run = PayrollRun::factory()->for($period, 'payPeriod')->create();

    expect($run->payPeriod)->toBeInstanceOf(PayPeriod::class)
        ->and($run->payPeriod->id)->toBe($period->id);
});

it('has many payslips', function () {
    $run = PayrollRun::factory()->create();
    Payslip::factory()->count(3)->for($run, 'payrollRun')->create();

    expect($run->payslips)->toHaveCount(3);
});

it('starts in draft status with zeroed totals', function () {
    $run = PayrollRun::factory()->create();

    expect($run->status)->toBe(PayrollRun::STATUS_DRAFT)
        ->and($run->total_employees)->toBe(0)
        ->and($run->total_employee_deductions_centavos)->toBe(0)
        ->and($run->total_employer_contributions_centavos)->toBe(0)
        ->and($run->total_net_pay_centavos)->toBe(0)
        ->and($run->isVoided())->toBeFalse()
        ->and($run->isMutable())->toBeTrue();
});

it('treats computed runs as mutable but voided runs as not', function () {
    $computed = PayrollRun::factory()->computed()->create();
    $voided = PayrollRun::factory()->voided()->create();

    expect($computed->isMutable())->toBeTrue()
        ->and($computed->isVoided())->toBeFalse()
        ->and($voided->isMutable())->toBeFalse()
        ->and($voided->isVoided())->toBeTrue();
});

it('treats approved and posted runs as immutable', function () {
    $approved = PayrollRun::factory()->approved()->create();
    $posted = PayrollRun::factory()->create(['status' => PayrollRun::STATUS_POSTED]);

    expect($approved->isMutable())->toBeFalse()
        ->and($posted->isMutable())->toBeFalse();
});

it('exposes approved-by and voided-by user relationships', function () {
    $approver = User::factory()->create();
    $voider = User::factory()->create();

    $run = PayrollRun::factory()->create([
        'status' => PayrollRun::STATUS_APPROVED,
        'approved_at' => now(),
        'approved_by_user_id' => $approver->id,
        'voided_at' => now(),
        'voided_by_user_id' => $voider->id,
    ]);

    expect($run->approvedBy?->id)->toBe($approver->id)
        ->and($run->voidedBy?->id)->toBe($voider->id);
});
