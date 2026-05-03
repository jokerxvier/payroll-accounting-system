<?php

declare(strict_types=1);

use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Pins the EmployeeLoan persistence-time amortization rules:
 *   - applyAmortization clamps to remaining balance
 *   - lock_version increments on every apply
 *   - closed_on stamps when the balance hits zero
 *   - the actual applied amount is returned (== clamped Money)
 *   - scopeOpen excludes closed loans and zero-balance loans
 */

it('applies a partial payment, returns the same amount, decrements the balance, and bumps lock_version', function (): void {
    $loan = EmployeeLoan::factory()
        ->for(EmployeeProfile::factory())
        ->create([
            'principal_centavos' => 3_000_000,           // PHP 30,000
            'outstanding_balance_centavos' => 3_000_000,
            'monthly_amortization_centavos' => 250_000,  // PHP 2,500
            'lock_version' => 0,
        ]);

    $applied = $loan->applyAmortization(Money::fromCentavos(250_000));

    expect($applied->centavos())->toBe(250_000)
        ->and($loan->refresh()->outstanding_balance_centavos)->toBe(2_750_000)
        ->and($loan->lock_version)->toBe(1)
        ->and($loan->closed_on)->toBeNull();
});

it('clamps an over-payment to the outstanding balance and returns the clamped value', function (): void {
    $loan = EmployeeLoan::factory()
        ->for(EmployeeProfile::factory())
        ->create([
            'outstanding_balance_centavos' => 100_000, // PHP 1,000 left
            'monthly_amortization_centavos' => 250_000,
            'lock_version' => 7,
        ]);

    // Caller naively passes the monthly amortization; the row only owes 1,000.
    $applied = $loan->applyAmortization(Money::fromCentavos(250_000));

    expect($applied->centavos())->toBe(100_000)
        ->and($loan->refresh()->outstanding_balance_centavos)->toBe(0)
        ->and($loan->lock_version)->toBe(8);
});

it('stamps closed_on with today when the balance reaches zero', function (): void {
    $loan = EmployeeLoan::factory()
        ->for(EmployeeProfile::factory())
        ->create([
            'outstanding_balance_centavos' => 250_000,
            'monthly_amortization_centavos' => 250_000,
        ]);

    expect($loan->closed_on)->toBeNull();

    $loan->applyAmortization(Money::fromCentavos(250_000));

    $loan->refresh();
    $closedOn = $loan->closed_on;

    expect($loan->outstanding_balance_centavos)->toBe(0)
        ->and($closedOn)->not->toBeNull();

    expect($closedOn?->toDateString())->toBe(now()->toDateString());
});

it('does NOT stamp closed_on while the balance is still positive', function (): void {
    $loan = EmployeeLoan::factory()
        ->for(EmployeeProfile::factory())
        ->create([
            'outstanding_balance_centavos' => 500_000,
            'monthly_amortization_centavos' => 250_000,
        ]);

    $loan->applyAmortization(Money::fromCentavos(250_000));

    $loan->refresh();
    expect($loan->outstanding_balance_centavos)->toBe(250_000)
        ->and($loan->closed_on)->toBeNull();
});

it('scopeOpen excludes loans that have been explicitly closed', function (): void {
    $profile = EmployeeProfile::factory()->create();

    $open = EmployeeLoan::factory()->for($profile)->create([
        'outstanding_balance_centavos' => 100_000,
        'closed_on' => null,
    ]);
    $closed = EmployeeLoan::factory()->for($profile)->closed()->create();

    $ids = EmployeeLoan::query()->open()->pluck('id');

    expect($ids)->toContain($open->id)
        ->and($ids)->not->toContain($closed->id);
});

it('scopeOpen excludes zero-balance loans even when closed_on is null', function (): void {
    $profile = EmployeeProfile::factory()->create();

    $live = EmployeeLoan::factory()->for($profile)->create([
        'outstanding_balance_centavos' => 250_000,
    ]);
    $zeroButOpen = EmployeeLoan::factory()->for($profile)->create([
        'outstanding_balance_centavos' => 0,
        'closed_on' => null,
    ]);

    $ids = EmployeeLoan::query()->open()->pluck('id');

    expect($ids)->toContain($live->id)
        ->and($ids)->not->toContain($zeroButOpen->id);
});

it('handles a chain of partial payments correctly', function (): void {
    $loan = EmployeeLoan::factory()
        ->for(EmployeeProfile::factory())
        ->create([
            'outstanding_balance_centavos' => 1_000_000, // PHP 10,000
            'monthly_amortization_centavos' => 250_000,
            'lock_version' => 0,
        ]);

    $loan->applyAmortization(Money::fromCentavos(250_000));
    $loan->applyAmortization(Money::fromCentavos(250_000));
    $loan->applyAmortization(Money::fromCentavos(250_000));
    $final = $loan->applyAmortization(Money::fromCentavos(250_000));

    $loan->refresh();
    expect($final->centavos())->toBe(250_000)
        ->and($loan->outstanding_balance_centavos)->toBe(0)
        ->and($loan->lock_version)->toBe(4)
        ->and($loan->closed_on)->not->toBeNull();
});
