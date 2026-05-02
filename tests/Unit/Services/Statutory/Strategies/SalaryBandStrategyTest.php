<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\Strategies\SalaryBandStrategy;
use App\ValueObjects\Money;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Boundary tests for SalaryBandStrategy.
 *
 * Reference rules: the SSS factory state's `bands` array. The factory has
 * three bands; reading them via the factory keeps test data and seed data
 * in lockstep.
 */

beforeEach(function (): void {
    $this->strategy = new SalaryBandStrategy;
    /** @var array<string, mixed> $rules */
    $rules = StatutoryContribution::factory()->sss()->make()->rules;
    $this->rules = $rules;
});

it('uses the lowest band when input is below the first lower bound (minimum-MSC behavior)', function () {
    $result = $this->strategy->compute(Money::fromCentavos(0), $this->rules);

    // Lowest band: contribution_base=500_000, ee=22_500, er=47_500, er_aux=1_000
    expect($result->employeeShare->centavos())->toBe(22_500)
        ->and($result->employerShare->centavos())->toBe(47_500)
        ->and($result->employerEcShare->centavos())->toBe(1_000)
        ->and($result->taxableAmount->centavos())->toBe(500_000);
});

it('uses the first band when input equals its lower bound', function () {
    // First band lower=0, so any non-negative input below 525_000 lands here.
    $result = $this->strategy->compute(Money::fromCentavos(0), $this->rules);

    expect($result->taxableAmount->centavos())->toBe(500_000);
});

it('uses the first band for input strictly inside its range', function () {
    $result = $this->strategy->compute(Money::fromCentavos(400_000), $this->rules);

    expect($result->employeeShare->centavos())->toBe(22_500)
        ->and($result->employerShare->centavos())->toBe(47_500)
        ->and($result->employerEcShare->centavos())->toBe(1_000)
        ->and($result->taxableAmount->centavos())->toBe(500_000);
});

it('switches to the second band at its lower-inclusive boundary', function () {
    $result = $this->strategy->compute(Money::fromCentavos(525_000), $this->rules);

    // Second band: contribution_base=1_500_000, ee=67_500, er=142_500
    expect($result->employeeShare->centavos())->toBe(67_500)
        ->and($result->employerShare->centavos())->toBe(142_500)
        ->and($result->employerEcShare->centavos())->toBe(1_000)
        ->and($result->taxableAmount->centavos())->toBe(1_500_000);
});

it('uses the top band (upper=null) for inputs above the second-band upper bound', function () {
    $result = $this->strategy->compute(Money::fromCentavos(2_500_000), $this->rules);

    // Top band: contribution_base=2_000_000, ee=90_000, er=190_000, er_aux=3_000
    expect($result->employeeShare->centavos())->toBe(90_000)
        ->and($result->employerShare->centavos())->toBe(190_000)
        ->and($result->employerEcShare->centavos())->toBe(3_000)
        ->and($result->taxableAmount->centavos())->toBe(2_000_000);
});

it('maps employer_aux_share to employerEcShare verbatim', function () {
    // Top band has er_aux=3_000 distinct from regular er=190_000.
    $result = $this->strategy->compute(Money::fromCentavos(2_500_000), $this->rules);

    expect($result->employerEcShare->centavos())->toBe(3_000)
        ->and($result->employerShare->centavos())->toBe(190_000);
});

it('adds employee_aux_share into employeeShare', function () {
    // Construct a custom band with a non-zero employee_aux_share to exercise the path.
    $rules = [
        'bands' => [
            [
                'lower' => 0,
                'upper' => null,
                'contribution_base' => 1_000_000,
                'employee_share' => 50_000,
                'employer_share' => 100_000,
                'employer_aux_share' => 0,
                'employee_aux_share' => 5_000,
            ],
        ],
    ];

    $result = $this->strategy->compute(Money::fromCentavos(1_500_000), $rules);

    // 50_000 + 5_000 = 55_000
    expect($result->employeeShare->centavos())->toBe(55_000)
        ->and($result->employerShare->centavos())->toBe(100_000);
});

it('reports the band contribution_base as taxableAmount, not the input salary', function () {
    // 22,134 PHP salary with the SSS factory's first band -> taxable is the
    // band's contribution_base of 500_000, not the input.
    $result = $this->strategy->compute(Money::fromCentavos(2_213_400), $this->rules);

    // 2_213_400 is in [1_975_000, ∞): top band, contribution_base=2_000_000
    expect($result->taxableAmount->centavos())->toBe(2_000_000)
        ->and($result->taxableAmount->centavos())->not->toBe(2_213_400);
});

it('rejects rules with an empty bands array', function () {
    $this->strategy->validateRules(['bands' => []]);
})->throws(InvalidArgumentException::class, 'non-empty');

it('rejects rules where a band is missing required keys', function () {
    $this->strategy->validateRules([
        'bands' => [
            [
                'lower' => 0,
                'upper' => null,
                // missing contribution_base
                'employee_share' => 0,
                'employer_share' => 0,
                'employer_aux_share' => 0,
                'employee_aux_share' => 0,
            ],
        ],
    ]);
})->throws(InvalidArgumentException::class, 'contribution_base');

it('rejects rules whose bands are not sorted ascending by lower', function () {
    $this->strategy->validateRules([
        'bands' => [
            [
                'lower' => 1000,
                'upper' => 2000,
                'contribution_base' => 1000,
                'employee_share' => 0,
                'employer_share' => 0,
                'employer_aux_share' => 0,
                'employee_aux_share' => 0,
            ],
            [
                'lower' => 500,
                'upper' => null,
                'contribution_base' => 500,
                'employee_share' => 0,
                'employer_share' => 0,
                'employer_aux_share' => 0,
                'employee_aux_share' => 0,
            ],
        ],
    ]);
})->throws(InvalidArgumentException::class, 'sorted');

it('accepts the SSS factory rules without throwing', function () {
    $this->strategy->validateRules($this->rules);
})->throwsNoExceptions();
