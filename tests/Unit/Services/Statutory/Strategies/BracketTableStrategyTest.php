<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\Strategies\BracketTableStrategy;
use App\ValueObjects\Money;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Boundary tests for BracketTableStrategy.
 *
 * Reference rules: the BIR factory state's `monthly` and `semi_monthly` brackets.
 * Re-deriving them inside each test would just duplicate factory data, so each
 * test pulls them from `StatutoryContribution::factory()->bir()->make()->rules`.
 *
 * Hand-computed reference cases are commented inline so a future reader can
 * re-verify the centavo math without rebuilding the bracket table mentally.
 */

beforeEach(function (): void {
    $this->strategy = new BracketTableStrategy;
    /** @var array<string, mixed> $rules */
    $rules = StatutoryContribution::factory()->bir()->make()->rules;
    $this->rules = $rules;
});

it('returns zero tax when input is below the first bracket boundary', function () {
    $result = $this->strategy->compute(
        Money::fromCentavos(0),
        $this->rules,
        ['period_type' => 'monthly'],
    );

    expect($result->employeeShare->centavos())->toBe(0)
        ->and($result->employerShare->centavos())->toBe(0)
        ->and($result->employerEcShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(0);
});

it('returns zero tax inside the lowest (zero-rate) bracket', function () {
    // 1,000,000 centavos is inside [0, 2_083_300): zero-rate bracket.
    $result = $this->strategy->compute(
        Money::fromCentavos(1_000_000),
        $this->rules,
        ['period_type' => 'monthly'],
    );

    expect($result->employeeShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(1_000_000);
});

it('still falls in the lowest bracket just below the second bracket boundary', function () {
    // 2_083_299 is one centavo below the second bracket's lower bound.
    $result = $this->strategy->compute(
        Money::fromCentavos(2_083_299),
        $this->rules,
        ['period_type' => 'monthly'],
    );

    expect($result->employeeShare->centavos())->toBe(0);
});

it('uses the second bracket exactly at its lower-inclusive boundary', function () {
    // 2_083_300 hits the second bracket's lower bound.
    // tax = 0 + (2_083_300 - 2_083_300) × 1500 / 10000 = 0
    $result = $this->strategy->compute(
        Money::fromCentavos(2_083_300),
        $this->rules,
        ['period_type' => 'monthly'],
    );

    expect($result->employeeShare->centavos())->toBe(0);
});

it('applies the second bracket rate inside its range', function () {
    // 2_500_000 in [2_083_300, 3_333_300):
    // tax = 0 + (2_500_000 - 2_083_300) × 1500 / 10000
    //     = 416_700 × 1500 / 10000
    //     = 625_050_000 / 10000
    //     = 62_505 (no remainder)
    $result = $this->strategy->compute(
        Money::fromCentavos(2_500_000),
        $this->rules,
        ['period_type' => 'monthly'],
    );

    expect($result->employeeShare->centavos())->toBe(62_505)
        ->and($result->taxableAmount->centavos())->toBe(2_500_000)
        ->and($result->employerShare->centavos())->toBe(0)
        ->and($result->employerEcShare->centavos())->toBe(0);
});

it('uses the top tier (upper=null) for inputs above the second-bracket upper bound', function () {
    // 4_000_000 in [3_333_300, ∞):
    // tax = 187_500 + (4_000_000 - 3_333_300) × 2000 / 10000
    //     = 187_500 + 666_700 × 2000 / 10000
    //     = 187_500 + 133_340
    //     = 320_840
    $result = $this->strategy->compute(
        Money::fromCentavos(4_000_000),
        $this->rules,
        ['period_type' => 'monthly'],
    );

    expect($result->employeeShare->centavos())->toBe(320_840);
});

it('selects the bracket array by period_type', function () {
    // 1_500_000 is well inside the monthly zero bracket [0, 2_083_300),
    // but the SAME amount in semi_monthly falls into [1_041_700, 1_666_700)
    // which charges:
    //   tax = 0 + (1_500_000 - 1_041_700) × 1500 / 10000
    //       = 458_300 × 1500 / 10000
    //       = 687_450_000 / 10000
    //       = 68_745
    $monthlyResult = $this->strategy->compute(
        Money::fromCentavos(1_500_000),
        $this->rules,
        ['period_type' => 'monthly'],
    );

    $semiResult = $this->strategy->compute(
        Money::fromCentavos(1_500_000),
        $this->rules,
        ['period_type' => 'semi_monthly'],
    );

    expect($monthlyResult->employeeShare->centavos())->toBe(0)
        ->and($semiResult->employeeShare->centavos())->toBe(68_745);
});

it('throws when context is missing period_type', function () {
    $this->strategy->compute(
        Money::fromCentavos(1_000_000),
        $this->rules,
        [],
    );
})->throws(InvalidArgumentException::class, 'period_type');

it('throws when period_type does not exist in rules', function () {
    $this->strategy->compute(
        Money::fromCentavos(1_000_000),
        $this->rules,
        ['period_type' => 'weekly'],
    );
})->throws(InvalidArgumentException::class, "period_type 'weekly'");

it('rejects rules with empty period_types', function () {
    $this->strategy->validateRules(['period_types' => []]);
})->throws(InvalidArgumentException::class, 'non-empty');

it('rejects rules whose first bracket does not start at lower=0', function () {
    $this->strategy->validateRules([
        'period_types' => [
            'monthly' => [
                ['lower' => 1000, 'upper' => 2000, 'base_tax' => 0, 'excess_rate_bp' => 0, 'excess_over' => 0],
            ],
        ],
    ]);
})->throws(InvalidArgumentException::class, 'lower=0');

it('rejects rules with brackets not sorted ascending by lower', function () {
    $this->strategy->validateRules([
        'period_types' => [
            'monthly' => [
                ['lower' => 0, 'upper' => 1000, 'base_tax' => 0, 'excess_rate_bp' => 0, 'excess_over' => 0],
                ['lower' => 0, 'upper' => 2000, 'base_tax' => 0, 'excess_rate_bp' => 0, 'excess_over' => 0],
            ],
        ],
    ]);
})->throws(InvalidArgumentException::class, 'sorted');

it('rejects rules where a bracket is missing required keys', function () {
    $this->strategy->validateRules([
        'period_types' => [
            'monthly' => [
                ['lower' => 0, 'upper' => 1000, 'excess_rate_bp' => 0, 'excess_over' => 0],
            ],
        ],
    ]);
})->throws(InvalidArgumentException::class, 'base_tax');

it('accepts the BIR factory rules without throwing', function () {
    $this->strategy->validateRules($this->rules);
})->throwsNoExceptions();
