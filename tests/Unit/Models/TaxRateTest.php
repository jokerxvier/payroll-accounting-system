<?php

declare(strict_types=1);

use App\Models\Pas\TaxRate;
use App\ValueObjects\Money;

/*
 * TaxRate — VAT arithmetic.
 *
 * Everything here stays in integer centavos and basis points. The pinned
 * behaviours are:
 *  - taxOn()     computes tax ON TOP of a VAT-exclusive amount.
 *  - taxWithin() extracts the tax ALREADY INSIDE a VAT-inclusive amount,
 *                using G * r / (10000 + r) rather than G * r / 10000.
 *  - the two are consistent: net + taxOn(net) == gross, and
 *    taxWithin(gross) == taxOn(net) for the same underlying sale.
 *  - exempt and zero-rated rates produce no tax but remain distinct types.
 */

it('computes VAT on a VAT-exclusive amount', function () {
    $rate = TaxRate::factory()->vatSales()->make();

    // PHP 1,000.00 at 12% → PHP 120.00
    expect($rate->taxOn(Money::fromDecimalString('1000.00'))->toDecimalString())
        ->toBe('120.00');
});

it('extracts VAT already embedded in a VAT-inclusive amount', function () {
    $rate = TaxRate::factory()->vatSales()->make();

    // PHP 1,120.00 gross at 12% → PHP 120.00 tax, NOT 134.40.
    // 134.40 is what the exclusive formula would wrongly return, i.e.
    // taxing the tax.
    expect($rate->taxWithin(Money::fromDecimalString('1120.00'))->toDecimalString())
        ->toBe('120.00');
});

it('keeps the inclusive and exclusive paths consistent for the same sale', function () {
    $rate = TaxRate::factory()->vatSales()->make();

    $net = Money::fromDecimalString('2500.00');
    $tax = $rate->taxOn($net);
    $gross = $net->plus($tax);

    expect($gross->toDecimalString())->toBe('2800.00');
    // Round-trip: pulling the tax back out of the gross returns the same
    // figure that was added to produce it.
    expect($rate->taxWithin($gross)->centavos())->toBe($tax->centavos());
});

it('rounds half to even rather than drifting, on both paths', function () {
    $rate = TaxRate::factory()->vatSales()->make();

    // 0.04 * 12% = 0.0048 → rounds to 0.00 (below half a centavo).
    expect($rate->taxOn(Money::fromDecimalString('0.04'))->toDecimalString())->toBe('0.00');
    // 0.05 * 12% = 0.006 → rounds to 0.01 (above half a centavo).
    expect($rate->taxOn(Money::fromDecimalString('0.05'))->toDecimalString())->toBe('0.01');

    // Results are always whole centavos — never a fractional remainder.
    foreach (['1.01', '33.33', '999.99', '7.77'] as $amount) {
        $result = $rate->taxOn(Money::fromDecimalString($amount));
        expect($result->centavos())->toBeInt();
    }
});

it('returns zero tax for exempt and zero-rated rates on both paths', function (string $state) {
    $rate = TaxRate::factory()->{$state}()->make();

    expect($rate->taxOn(Money::fromDecimalString('1000.00'))->isZero())->toBeTrue();
    expect($rate->taxWithin(Money::fromDecimalString('1000.00'))->isZero())->toBeTrue();
})->with(['exempt', 'zeroRated']);

it('keeps exempt and zero-rated as distinct types despite both being 0%', function () {
    // BIR requires VAT-exempt sales and zero-rated sales reported as separate
    // invoice subtotals, so they must not collapse into one "0%" rate.
    $exempt = TaxRate::factory()->exempt()->make();
    $zeroRated = TaxRate::factory()->zeroRated()->make();

    expect($exempt->rate_bps)->toBe(0)
        ->and($zeroRated->rate_bps)->toBe(0)
        ->and($exempt->type)->not()->toBe($zeroRated->type);
});

it('reports which rates actually post a tax line', function () {
    expect(TaxRate::factory()->vatSales()->make()->postsTax())->toBeTrue();
    expect(TaxRate::factory()->vatPurchase()->make()->postsTax())->toBeTrue();
    expect(TaxRate::factory()->exempt()->make()->postsTax())->toBeFalse();
    expect(TaxRate::factory()->zeroRated()->make()->postsTax())->toBeFalse();

    // A VAT-typed rate set to 0% posts nothing either — there is no tax to book.
    $zeroVat = TaxRate::factory()->vatSales()->make(['rate_bps' => 0]);
    expect($zeroVat->postsTax())->toBeFalse();
});

it('renders a human-readable percentage label', function (int $bps, string $expected) {
    $rate = TaxRate::factory()->make(['rate_bps' => $bps]);

    expect($rate->ratePercentLabel())->toBe($expected);
})->with([
    [1200, '12%'],
    [0, '0%'],
    [1250, '12.5%'],
    [10000, '100%'],
    [25, '0.25%'],
]);
