<?php

declare(strict_types=1);

use App\Services\Statutory\Strategies\FlatPercentageStrategy;
use App\ValueObjects\Money;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Boundary tests for FlatPercentageStrategy.
 *
 * Distinct from PercentageWithCapStrategy in two ways:
 *   - There is NO floor — inputs below any threshold pass through untouched.
 *   - The cap is OPTIONAL (`null` means no cap).
 *
 * Every cent value below is hand-computed in the comment immediately above
 * the assertion so a reviewer can verify without re-running the math.
 */

beforeEach(function (): void {
    $this->strategy = new FlatPercentageStrategy;
});

it('computes a 10% employee-only contribution with no cap', function () {
    // rate_bp=1000 (10%), 100/0 split, no cap, input ₱100,000.00 (10_000_000 cents)
    // total = 10_000_000 × 1000 / 10000 = 1_000_000
    // ee = 1_000_000 × 10000 / 10000 = 1_000_000   (₱10,000.00)
    // er = 1_000_000 - 1_000_000 = 0               (₱0.00)
    $rules = [
        'rate_bp' => 1_000,
        'employee_share_bp' => 10_000,
        'employer_share_bp' => 0,
        'cap' => null,
    ];

    $result = $this->strategy->compute(Money::fromCentavos(10_000_000), $rules);

    expect($result->employeeShare->centavos())->toBe(1_000_000)
        ->and($result->employerShare->centavos())->toBe(0)
        ->and($result->employerEcShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(10_000_000);
});

it('splits a 10% contribution 50/50 between employee and employer', function () {
    // rate_bp=1000 (10%), 50/50 split, no cap, input ₱100,000.00
    // total = 1_000_000
    // ee = 1_000_000 × 5000 / 10000 = 500_000   (₱5,000.00)
    // er = 1_000_000 - 500_000 = 500_000        (₱5,000.00)
    $rules = [
        'rate_bp' => 1_000,
        'employee_share_bp' => 5_000,
        'employer_share_bp' => 5_000,
        'cap' => null,
    ];

    $result = $this->strategy->compute(Money::fromCentavos(10_000_000), $rules);

    expect($result->employeeShare->centavos())->toBe(500_000)
        ->and($result->employerShare->centavos())->toBe(500_000)
        ->and($result->taxableAmount->centavos())->toBe(10_000_000);
});

it('clamps the input to the cap when one is provided', function () {
    // rate_bp=1000 (10%), 100/0 split, cap ₱50,000 (5_000_000), input ₱100,000.
    // cappedInput = min(input, cap) = 5_000_000
    // total = 5_000_000 × 1000 / 10000 = 500_000  (₱5,000.00)
    // ee = 500_000; er = 0
    $rules = [
        'rate_bp' => 1_000,
        'employee_share_bp' => 10_000,
        'employer_share_bp' => 0,
        'cap' => 5_000_000,
    ];

    $result = $this->strategy->compute(Money::fromCentavos(10_000_000), $rules);

    expect($result->employeeShare->centavos())->toBe(500_000)
        ->and($result->employerShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(5_000_000);
});

it('keeps employee + employer == total in cents on an odd-cent split', function () {
    // rate_bp=333 (3.33%) on ₱100,001.00 (10_000_100 cents), 50/50, no cap.
    //
    // total = 10_000_100 × 333 / 10000
    //       = 3_330_033_300 / 10000
    //       intdiv → 333_003, remainder 3_300
    //       2 × 3_300 = 6_600 < 10_000 → strictly less than half → 333_003
    //
    // ee    = 333_003 × 5000 / 10000
    //       = 1_665_015_000 / 10000
    //       intdiv → 166_501, remainder 5_000 → exact halfway.
    //       quotient 166_501 is odd → banker's rounds to even → 166_502
    //
    // er    = 333_003 - 166_502 = 166_501
    // ee + er = 333_003 = total  ✓
    $rules = [
        'rate_bp' => 333,
        'employee_share_bp' => 5_000,
        'employer_share_bp' => 5_000,
        'cap' => null,
    ];

    $result = $this->strategy->compute(Money::fromCentavos(10_000_100), $rules);

    expect($result->employeeShare->centavos())->toBe(166_502)
        ->and($result->employerShare->centavos())->toBe(166_501)
        ->and($result->employeeShare->plus($result->employerShare)->centavos())->toBe(333_003);
});

it('always returns zero employerEcShare', function () {
    $rules = [
        'rate_bp' => 1_000,
        'employee_share_bp' => 5_000,
        'employer_share_bp' => 5_000,
        'cap' => null,
    ];

    $result = $this->strategy->compute(Money::fromCentavos(5_000_000), $rules);

    expect($result->employerEcShare->centavos())->toBe(0);
});

it('rejects rules with rate_bp above 10000 basis points', function () {
    $this->strategy->validateRules([
        'rate_bp' => 20_000,
        'employee_share_bp' => 5_000,
        'employer_share_bp' => 5_000,
        'cap' => null,
    ]);
})->throws(InvalidArgumentException::class, 'rate_bp');

it('rejects rules whose share split does not sum to 10000 basis points', function () {
    $this->strategy->validateRules([
        'rate_bp' => 1_000,
        'employee_share_bp' => 6_000,
        'employer_share_bp' => 6_000,
        'cap' => null,
    ]);
})->throws(InvalidArgumentException::class, '10000');

it('accepts a well-formed rules payload with no cap', function () {
    $this->strategy->validateRules([
        'rate_bp' => 1_000,
        'employee_share_bp' => 5_000,
        'employer_share_bp' => 5_000,
        'cap' => null,
    ]);
})->throwsNoExceptions();

it('accepts a well-formed rules payload with a cap', function () {
    $this->strategy->validateRules([
        'rate_bp' => 1_000,
        'employee_share_bp' => 5_000,
        'employer_share_bp' => 5_000,
        'cap' => 5_000_000,
    ]);
})->throwsNoExceptions();
