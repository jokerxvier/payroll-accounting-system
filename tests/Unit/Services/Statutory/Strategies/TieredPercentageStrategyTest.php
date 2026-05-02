<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\Strategies\TieredPercentageStrategy;
use App\ValueObjects\Money;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Boundary tests for TieredPercentageStrategy.
 *
 * Reference rules: the Pag-IBIG factory state.
 *   - threshold = 150_000 centavos
 *   - max_msc   = 500_000 centavos
 *   - lower:    ee_bp=100, er_bp=200
 *   - upper:    ee_bp=200, er_bp=200
 */

beforeEach(function (): void {
    $this->strategy = new TieredPercentageStrategy;
    /** @var array<string, mixed> $rules */
    $rules = StatutoryContribution::factory()->pagibig()->make()->rules;
    $this->rules = $rules;
});

it('uses the lower-tier rates when input is strictly below the threshold', function () {
    // 100_000 ≤ threshold 150_000 → lower (ee_bp=100, er_bp=200).
    // ee = 100_000 × 100 / 10000 = 1_000
    // er = 100_000 × 200 / 10000 = 2_000
    $result = $this->strategy->compute(Money::fromCentavos(100_000), $this->rules);

    expect($result->employeeShare->centavos())->toBe(1_000)
        ->and($result->employerShare->centavos())->toBe(2_000)
        ->and($result->employerEcShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(100_000);
});

it('still uses the lower tier when input equals the threshold (<= semantics)', function () {
    // 150_000 == threshold → still lower (≤ rule).
    // ee = 150_000 × 100 / 10000 = 1_500
    // er = 150_000 × 200 / 10000 = 3_000
    $result = $this->strategy->compute(Money::fromCentavos(150_000), $this->rules);

    expect($result->employeeShare->centavos())->toBe(1_500)
        ->and($result->employerShare->centavos())->toBe(3_000);
});

it('switches to the upper-tier rates above the threshold', function () {
    // 200_000 > threshold → upper (ee_bp=200, er_bp=200).
    // ee = 200_000 × 200 / 10000 = 4_000
    // er = 200_000 × 200 / 10000 = 4_000
    $result = $this->strategy->compute(Money::fromCentavos(200_000), $this->rules);

    expect($result->employeeShare->centavos())->toBe(4_000)
        ->and($result->employerShare->centavos())->toBe(4_000)
        ->and($result->taxableAmount->centavos())->toBe(200_000);
});

it('caps the input at max_msc and applies the upper tier', function () {
    // 600_000 > max_msc 500_000 → cappedInput = 500_000.
    // 500_000 > threshold → upper.
    // ee = 500_000 × 200 / 10000 = 10_000
    // er = 500_000 × 200 / 10000 = 10_000
    $result = $this->strategy->compute(Money::fromCentavos(600_000), $this->rules);

    expect($result->employeeShare->centavos())->toBe(10_000)
        ->and($result->employerShare->centavos())->toBe(10_000)
        ->and($result->taxableAmount->centavos())->toBe(500_000);
});

it('reports the cappedInput as taxableAmount, not the original input', function () {
    $result = $this->strategy->compute(Money::fromCentavos(900_000), $this->rules);

    expect($result->taxableAmount->centavos())->toBe(500_000);
});

it('returns zero employerEcShare for every input', function () {
    foreach ([0, 100_000, 150_000, 200_000, 500_000, 1_000_000] as $cents) {
        $result = $this->strategy->compute(Money::fromCentavos($cents), $this->rules);
        expect($result->employerEcShare->centavos())->toBe(0);
    }
});

it('caps at max_msc even when input is exactly at max_msc', function () {
    // 500_000 == max_msc → cappedInput = 500_000.
    // 500_000 > threshold (150_000) → upper.
    $result = $this->strategy->compute(Money::fromCentavos(500_000), $this->rules);

    expect($result->taxableAmount->centavos())->toBe(500_000)
        ->and($result->employeeShare->centavos())->toBe(10_000)
        ->and($result->employerShare->centavos())->toBe(10_000);
});

it('rejects rules where threshold exceeds max_msc', function () {
    $this->strategy->validateRules([
        'threshold' => 100,
        'max_msc' => 50,
        'lower' => ['ee_bp' => 100, 'er_bp' => 200],
        'upper' => ['ee_bp' => 200, 'er_bp' => 200],
    ]);
})->throws(InvalidArgumentException::class, 'threshold');

it('rejects rules missing ee_bp on a tier', function () {
    $this->strategy->validateRules([
        'threshold' => 100,
        'max_msc' => 1000,
        'lower' => ['er_bp' => 200],
        'upper' => ['ee_bp' => 200, 'er_bp' => 200],
    ]);
})->throws(InvalidArgumentException::class, 'ee_bp');

it('rejects rules missing er_bp on a tier', function () {
    $this->strategy->validateRules([
        'threshold' => 100,
        'max_msc' => 1000,
        'lower' => ['ee_bp' => 100, 'er_bp' => 200],
        'upper' => ['ee_bp' => 200],
    ]);
})->throws(InvalidArgumentException::class, 'er_bp');

it('rejects rules with negative threshold', function () {
    $this->strategy->validateRules([
        'threshold' => -1,
        'max_msc' => 1000,
        'lower' => ['ee_bp' => 100, 'er_bp' => 200],
        'upper' => ['ee_bp' => 200, 'er_bp' => 200],
    ]);
})->throws(InvalidArgumentException::class, 'threshold');

it('accepts the Pag-IBIG factory rules without throwing', function () {
    $this->strategy->validateRules($this->rules);
})->throwsNoExceptions();
