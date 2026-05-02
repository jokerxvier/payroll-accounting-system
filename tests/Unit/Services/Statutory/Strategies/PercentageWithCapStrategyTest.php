<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\Strategies\PercentageWithCapStrategy;
use App\ValueObjects\Money;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Boundary tests for PercentageWithCapStrategy.
 *
 * Reference rules: the PhilHealth factory state — 5% rate, [10_000.00, 100_000.00]
 * floor/ceiling, 50/50 split.
 */

beforeEach(function (): void {
    $this->strategy = new PercentageWithCapStrategy;
    /** @var array<string, mixed> $rules */
    $rules = StatutoryContribution::factory()->philhealth()->make()->rules;
    $this->rules = $rules;
});

it('clamps inputs below floor up to the floor', function () {
    // Input 500_000 < floor 1_000_000 → cappedInput = 1_000_000.
    // total = 1_000_000 × 500 / 10000 = 50_000
    // ee = 50_000 × 5000 / 10000 = 25_000
    // er = 50_000 - 25_000 = 25_000
    $result = $this->strategy->compute(Money::fromCentavos(500_000), $this->rules);

    expect($result->employeeShare->centavos())->toBe(25_000)
        ->and($result->employerShare->centavos())->toBe(25_000)
        ->and($result->taxableAmount->centavos())->toBe(1_000_000);
});

it('clamps inputs above ceiling down to the ceiling', function () {
    // Input 15_000_000 > ceiling 10_000_000 → cappedInput = 10_000_000.
    // total = 10_000_000 × 500 / 10000 = 500_000
    // ee = 500_000 × 5000 / 10000 = 250_000; er = 250_000
    $result = $this->strategy->compute(Money::fromCentavos(15_000_000), $this->rules);

    expect($result->employeeShare->centavos())->toBe(250_000)
        ->and($result->employerShare->centavos())->toBe(250_000)
        ->and($result->taxableAmount->centavos())->toBe(10_000_000);
});

it('uses the input itself when inside the floor/ceiling range', function () {
    // 5_000_000 in [1_000_000, 10_000_000].
    // total = 5_000_000 × 500 / 10000 = 250_000
    // ee = 250_000 × 5000 / 10000 = 125_000; er = 250_000 - 125_000 = 125_000
    $result = $this->strategy->compute(Money::fromCentavos(5_000_000), $this->rules);

    expect($result->employeeShare->centavos())->toBe(125_000)
        ->and($result->employerShare->centavos())->toBe(125_000)
        ->and($result->taxableAmount->centavos())->toBe(5_000_000);
});

it('always splits the total exactly so employee + employer == total in cents', function () {
    // Sweep a few inputs and assert the invariant. No off-by-one.
    foreach ([1_000_000, 2_500_000, 5_000_000, 7_777_777, 10_000_000] as $cents) {
        $result = $this->strategy->compute(Money::fromCentavos($cents), $this->rules);
        $sum = $result->employeeShare->plus($result->employerShare);
        $expectedTotal = $result->total();
        expect($sum->centavos())->toBe($expectedTotal->centavos());
    }
});

it('handles odd-cent totals by giving the extra centavo to the employer', function () {
    // Custom rules: floor=ceiling=5, rate=100%, 50/50 split.
    // total = 5 × 10000 / 10000 = 5
    // ee = 5 × 5000 / 10000 = 2.5 → banker's rounding (round half to even) → 2
    // er = 5 - 2 = 3
    $rules = [
        'rate_bp' => 10_000,
        'floor' => 5,
        'ceiling' => 5,
        'split' => ['employee_bp' => 5_000, 'employer_bp' => 5_000],
    ];

    $result = $this->strategy->compute(Money::fromCentavos(5), $rules);

    expect($result->employeeShare->centavos())->toBe(2)
        ->and($result->employerShare->centavos())->toBe(3)
        ->and($result->employeeShare->plus($result->employerShare)->centavos())->toBe(5);
});

it('applies banker\'s rounding to the total premium', function () {
    // Construct a halfway total. Use rate_bp=5000 (50%) and cappedInput=3:
    // total = 3 × 5000 / 10000 = 15000 / 10000 = 1.5 → banker's rounds to 2 (even).
    $halfway1 = [
        'rate_bp' => 5_000,
        'floor' => 3,
        'ceiling' => 3,
        'split' => ['employee_bp' => 5_000, 'employer_bp' => 5_000],
    ];

    $r1 = $this->strategy->compute(Money::fromCentavos(3), $halfway1);
    expect($r1->total()->centavos())->toBe(2);

    // Halfway in the other direction: cappedInput=5, rate_bp=5000:
    // total = 5 × 5000 / 10000 = 2.5 → banker's rounds to 2 (even).
    $halfway2 = [
        'rate_bp' => 5_000,
        'floor' => 5,
        'ceiling' => 5,
        'split' => ['employee_bp' => 5_000, 'employer_bp' => 5_000],
    ];

    $r2 = $this->strategy->compute(Money::fromCentavos(5), $halfway2);
    expect($r2->total()->centavos())->toBe(2);
});

it('always returns zero employerEcShare', function () {
    $result = $this->strategy->compute(Money::fromCentavos(5_000_000), $this->rules);

    expect($result->employerEcShare->centavos())->toBe(0);
});

it('rejects rules where floor exceeds ceiling', function () {
    $this->strategy->validateRules([
        'rate_bp' => 500,
        'floor' => 100,
        'ceiling' => 50,
        'split' => ['employee_bp' => 5_000, 'employer_bp' => 5_000],
    ]);
})->throws(InvalidArgumentException::class, 'floor');

it('rejects rules whose split does not sum to 10000 basis points', function () {
    $this->strategy->validateRules([
        'rate_bp' => 500,
        'floor' => 0,
        'ceiling' => 100,
        'split' => ['employee_bp' => 4_000, 'employer_bp' => 5_000],
    ]);
})->throws(InvalidArgumentException::class, '10000');

it('rejects rules with negative rate_bp', function () {
    $this->strategy->validateRules([
        'rate_bp' => -500,
        'floor' => 0,
        'ceiling' => 100,
        'split' => ['employee_bp' => 5_000, 'employer_bp' => 5_000],
    ]);
})->throws(InvalidArgumentException::class, 'rate_bp');

it('accepts the PhilHealth factory rules without throwing', function () {
    $this->strategy->validateRules($this->rules);
})->throwsNoExceptions();
