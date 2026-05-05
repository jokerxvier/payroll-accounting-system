<?php

declare(strict_types=1);

use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\ValueObjects\PayPeriodInput;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('persists a monthly pay period with date casts', function () {
    $period = PayPeriod::factory()->monthly(2026, 5)->create();

    expect($period->code)->toBe('2026-05')
        ->and($period->frequency)->toBe(PayPeriod::FREQUENCY_MONTHLY)
        ->and($period->status)->toBe(PayPeriod::STATUS_DRAFT)
        ->and($period->start_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($period->start_date->toDateString())->toBe('2026-05-01')
        ->and($period->end_date->toDateString())->toBe('2026-05-31');
});

it('hydrates into a PayPeriodInput value object', function () {
    $period = PayPeriod::factory()->monthly(2026, 5)->create();

    $input = $period->toPayPeriodInput();

    expect($input)->toBeInstanceOf(PayPeriodInput::class)
        ->and($input->frequency())->toBe(PayPeriodInput::FREQUENCY_MONTHLY)
        ->and($input->start()->toDateString())->toBe('2026-05-01')
        ->and($input->end()->toDateString())->toBe('2026-05-31');
});

it('scopes to open periods', function () {
    PayPeriod::factory()->monthly(2026, 1)->create();
    PayPeriod::factory()->monthly(2026, 2)->open()->create();
    PayPeriod::factory()->monthly(2026, 3)->closed()->create();

    expect(PayPeriod::query()->open()->count())->toBe(1);
});

it('scopes by frequency', function () {
    PayPeriod::factory()->monthly(2026, 1)->create();
    PayPeriod::factory()->monthly(2026, 2)->create([
        'frequency' => PayPeriod::FREQUENCY_SEMI_MONTHLY,
        'end_date' => CarbonImmutable::create(2026, 2, 15),
    ]);

    expect(PayPeriod::query()->forFrequency(PayPeriod::FREQUENCY_MONTHLY)->count())->toBe(1)
        ->and(PayPeriod::query()->forFrequency(PayPeriod::FREQUENCY_SEMI_MONTHLY)->count())->toBe(1);
});

it('has many payroll runs', function () {
    $period = PayPeriod::factory()->monthly(2026, 5)->create();
    PayrollRun::factory()->count(2)->for($period, 'payPeriod')->create();

    expect($period->payrollRuns)->toHaveCount(2)
        ->and($period->payrollRuns->first())->toBeInstanceOf(PayrollRun::class);
});
