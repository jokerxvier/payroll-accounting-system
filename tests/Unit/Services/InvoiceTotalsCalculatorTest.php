<?php

declare(strict_types=1);

use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\TaxRate;
use App\Services\Accounting\InvoiceTotalsCalculator;
use Tests\TestCase;

/*
 * InvoiceTotalsCalculator — the arithmetic printed on the face of a BIR
 * sales invoice.
 *
 * Nothing here touches the database. Models are instantiated unsaved and
 * their tax-rate relation is set by hand, so every figure asserted is the
 * calculator's own output rather than something a factory arranged. TestCase
 * is applied only so the container can resolve the service; RefreshDatabase
 * is deliberately absent.
 */

uses(TestCase::class);

function calculator(): InvoiceTotalsCalculator
{
    return new InvoiceTotalsCalculator;
}

function vatRate(int $bps = 1200): TaxRate
{
    return new TaxRate(['code' => 'VAT', 'rate_bps' => $bps, 'type' => TaxRate::TYPE_VAT_SALES]);
}

function exemptRate(): TaxRate
{
    return new TaxRate(['code' => 'EXE', 'rate_bps' => 0, 'type' => TaxRate::TYPE_EXEMPT]);
}

function zeroRatedRate(): TaxRate
{
    return new TaxRate(['code' => 'ZR', 'rate_bps' => 0, 'type' => TaxRate::TYPE_ZERO_RATED]);
}

/**
 * An unsaved line with its rate attached, so the calculator reads a real
 * relation without a query.
 */
function line(int $unitPriceCentavos, ?TaxRate $rate = null, string $quantity = '1.0000'): InvoiceLine
{
    $line = new InvoiceLine([
        'description' => 'Tuition',
        'quantity' => $quantity,
        'unit_price_centavos' => $unitPriceCentavos,
    ]);

    $line->setRelation('taxRate', $rate);

    return $line;
}

/* ── Inclusive and exclusive describe the same sale ─────────────────── */

it('computes a VAT-exclusive sale', function () {
    // ₱10,000.00 at 12% → ₱1,200.00 VAT → ₱11,200.00.
    $totals = calculator()->calculate([line(1_000_000, vatRate())], false);

    expect($totals->vatableSalesCentavos)->toBe(1_000_000)
        ->and($totals->vatCentavos)->toBe(120_000)
        ->and($totals->totalCentavos())->toBe(1_120_000)
        ->and($totals->vatExemptSalesCentavos)->toBe(0)
        ->and($totals->zeroRatedSalesCentavos)->toBe(0);
});

it('reaches identical figures when the same sale is keyed VAT-inclusive', function () {
    // The operator types the gross the customer pays. Same sale, same
    // invoice — this is the property that makes the inclusive flag a data
    // entry convenience rather than a second kind of transaction.
    $exclusive = calculator()->calculate([line(1_000_000, vatRate())], false);
    $inclusive = calculator()->calculate([line(1_120_000, vatRate())], true);

    expect($inclusive->vatableSalesCentavos)->toBe($exclusive->vatableSalesCentavos)
        ->and($inclusive->vatCentavos)->toBe($exclusive->vatCentavos)
        ->and($inclusive->totalCentavos())->toBe($exclusive->totalCentavos());
});

it('never lets an inclusive line drift from the price that was keyed', function () {
    // net + tax must land back exactly on the gross the operator typed, for
    // every price — the reason the net is derived by subtraction rather than
    // by a second rounded division.
    foreach ([1, 7, 99, 333, 1_050, 99_999, 1_120_000, 7_777_777] as $gross) {
        $totals = calculator()->calculate([line($gross, vatRate())], true);

        expect($totals->totalCentavos())->toBe($gross, "gross {$gross} did not round-trip");
    }
});

/* ── The three sales buckets ────────────────────────────────────────── */

it('puts exempt sales in their own bucket with no tax', function () {
    $totals = calculator()->calculate([line(500_000, exemptRate())], false);

    expect($totals->vatExemptSalesCentavos)->toBe(500_000)
        ->and($totals->vatCentavos)->toBe(0)
        ->and($totals->vatableSalesCentavos)->toBe(0)
        ->and($totals->totalCentavos())->toBe(500_000);
});

it('keeps zero-rated sales separate from exempt', function () {
    // Both produce no tax. Merging them would be arithmetically harmless and
    // still wrong: the BIR reports them on different lines and the
    // distinction cannot be recovered once collapsed.
    $totals = calculator()->calculate([
        line(500_000, exemptRate()),
        line(300_000, zeroRatedRate()),
    ], false);

    expect($totals->vatExemptSalesCentavos)->toBe(500_000)
        ->and($totals->zeroRatedSalesCentavos)->toBe(300_000)
        ->and($totals->vatCentavos)->toBe(0)
        ->and($totals->totalCentavos())->toBe(800_000);
});

it('splits a mixed invoice across all three buckets', function () {
    $totals = calculator()->calculate([
        line(1_000_000, vatRate()),
        line(500_000, exemptRate()),
        line(300_000, zeroRatedRate()),
    ], false);

    expect($totals->vatableSalesCentavos)->toBe(1_000_000)
        ->and($totals->vatExemptSalesCentavos)->toBe(500_000)
        ->and($totals->zeroRatedSalesCentavos)->toBe(300_000)
        ->and($totals->vatCentavos)->toBe(120_000)
        ->and($totals->netCentavos())->toBe(1_800_000)
        ->and($totals->totalCentavos())->toBe(1_920_000);
});

it('treats a line with no tax rate as exempt', function () {
    $totals = calculator()->calculate([line(250_000)], false);

    expect($totals->vatExemptSalesCentavos)->toBe(250_000)
        ->and($totals->vatCentavos)->toBe(0);
});

it('leaves a misconfigured 0% VAT rate in VATable sales rather than hiding it', function () {
    // A vat_sales rate at 0 bps is a data problem. Reclassifying it as
    // zero-rated would produce a plausible-looking invoice and bury the
    // mistake, so it stays in the bucket its type declares.
    $totals = calculator()->calculate([line(400_000, vatRate(0))], false);

    expect($totals->vatableSalesCentavos)->toBe(400_000)
        ->and($totals->zeroRatedSalesCentavos)->toBe(0)
        ->and($totals->vatCentavos)->toBe(0);
});

/* ── Rounding is per line, because the customer reads the lines ─────── */

it('rounds each line separately so the total matches the printed lines', function () {
    // Three ₱0.05 lines at 12%. Each line's tax rounds up to ₱0.01, so the
    // invoice shows ₱0.03 of VAT. Taxing the ₱0.15 sum instead would give
    // ₱0.02 — defensible arithmetic that disagrees with the page.
    $totals = calculator()->calculate([
        line(5, vatRate()),
        line(5, vatRate()),
        line(5, vatRate()),
    ], false);

    expect(array_column($totals->lines, 'tax'))->toBe([1, 1, 1])
        ->and($totals->vatCentavos)->toBe(3)
        ->and($totals->vatableSalesCentavos)->toBe(15)
        ->and($totals->totalCentavos())->toBe(18);
});

it('always reports a total equal to the sum of its line figures', function () {
    $lines = [
        line(1_234_567, vatRate()),
        line(89, vatRate()),
        line(50_005, exemptRate()),
        line(7, zeroRatedRate()),
    ];

    $totals = calculator()->calculate($lines, false);

    $summed = array_sum(array_column($totals->lines, 'net'))
        + array_sum(array_column($totals->lines, 'tax'));

    expect($totals->totalCentavos())->toBe($summed);
});

it('handles a one-centavo line', function () {
    // ₱0.01 at 12% is ₱0.0012, which rounds to nothing. The line still
    // belongs in VATable sales.
    $totals = calculator()->calculate([line(1, vatRate())], false);

    expect($totals->vatableSalesCentavos)->toBe(1)
        ->and($totals->vatCentavos)->toBe(0)
        ->and($totals->totalCentavos())->toBe(1);
});

it('returns zeros for an invoice with no lines', function () {
    $totals = calculator()->calculate([], false);

    expect($totals->totalCentavos())->toBe(0)
        ->and($totals->lines)->toBe([]);
});

/* ── Quantity ───────────────────────────────────────────────────────── */

it('multiplies by a whole quantity', function () {
    $totals = calculator()->calculate([line(150_000, vatRate(), '3.0000')], false);

    expect($totals->vatableSalesCentavos)->toBe(450_000)
        ->and($totals->vatCentavos)->toBe(54_000);
});

it('multiplies by a fractional quantity without a float', function () {
    // 2.5 × ₱1,000.00 = ₱2,500.00. Rounding happens once, folded into the
    // same division that divides the quantity scale out.
    $totals = calculator()->calculate([line(100_000, vatRate(), '2.5000')], false);

    expect($totals->vatableSalesCentavos)->toBe(250_000)
        ->and($totals->vatCentavos)->toBe(30_000);
});

it('keeps the smallest expressible quantity exact', function () {
    // (float) '0.0001' * 10000 is the conversion that silently yields
    // 0.9999999 and floors to zero. Parsed as a string it stays exact.
    $totals = calculator()->calculate([line(1_000_000, exemptRate(), '0.0001')], false);

    expect($totals->vatExemptSalesCentavos)->toBe(100);
});

it('accepts a negative quantity for a discount line', function () {
    $totals = calculator()->calculate([
        line(1_000_000, vatRate()),
        line(100_000, vatRate(), '-1.0000'),
    ], false);

    expect($totals->vatableSalesCentavos)->toBe(900_000)
        ->and($totals->vatCentavos)->toBe(120_000 - 12_000)
        ->and($totals->totalCentavos())->toBe(1_008_000);
});

it('refuses a quantity that is not a 4-place decimal', function () {
    expect(fn () => calculator()->calculate([line(100_000, null, 'abc')], false))
        ->toThrow(InvalidArgumentException::class, 'not a decimal');
});

/* ── applyTo ────────────────────────────────────────────────────────── */

it('writes the computed figures onto the lines and the invoice', function () {
    $invoice = new Invoice(['is_vat_inclusive' => false]);
    $lines = [line(1_000_000, vatRate()), line(500_000, exemptRate())];

    calculator()->applyTo($invoice, $lines);

    expect($lines[0]->line_net_centavos)->toBe(1_000_000)
        ->and($lines[0]->line_tax_centavos)->toBe(120_000)
        ->and($lines[1]->line_net_centavos)->toBe(500_000)
        ->and($lines[1]->line_tax_centavos)->toBe(0)
        ->and($invoice->vatable_sales_centavos)->toBe(1_000_000)
        ->and($invoice->vat_exempt_sales_centavos)->toBe(500_000)
        ->and($invoice->vat_centavos)->toBe(120_000)
        ->and($invoice->total_centavos)->toBe(1_620_000);
});

it('reads the inclusive flag from the invoice it is applied to', function () {
    $invoice = new Invoice(['is_vat_inclusive' => true]);

    calculator()->applyTo($invoice, [line(1_120_000, vatRate())]);

    expect($invoice->vatable_sales_centavos)->toBe(1_000_000)
        ->and($invoice->vat_centavos)->toBe(120_000)
        ->and($invoice->total_centavos)->toBe(1_120_000);
});

it('leaves an applied invoice satisfying its own consistency invariant', function () {
    $invoice = new Invoice(['is_vat_inclusive' => false]);

    calculator()->applyTo($invoice, [
        line(1_234_567, vatRate()),
        line(50_005, exemptRate()),
        line(7, zeroRatedRate()),
    ]);

    expect($invoice->totalsAreConsistent())->toBeTrue();
});
