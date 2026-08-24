<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\TaxRate;
use App\ValueObjects\Money;
use InvalidArgumentException;

/**
 * Turns invoice lines into the figures printed on the face of a BIR sales
 * invoice.
 *
 * Three rules carry all the correctness here.
 *
 * **1. Round once per line, never on the total.** The invoice must equal the
 * sum of the lines a customer can add up on the printed page. Summing the
 * nets and taxing the sum would be arithmetically defensible and would still
 * be wrong: it drifts by centavos against the lines shown, and the customer
 * is looking at the lines.
 *
 * **2. Exempt and zero-rated are different buckets.** Both produce no tax,
 * so a single "untaxed" subtotal is tempting. The BIR reports them on
 * separate lines, and once merged the distinction cannot be recovered — so
 * they are separate columns and separate {@see TaxRate} types rather than
 * one shared 0% rate.
 *
 * **3. Inclusive and exclusive pricing describe the same sale.** Whether the
 * operator keys ₱10,000 + 12% or ₱11,200 VAT-inclusive, the invoice must
 * reach the same net, the same VAT, and the same total. `taxWithin()` on
 * {@see TaxRate} is what makes that hold — the tax inside a gross G is
 * `G × r / (10,000 + r)`, not `G × r / 10,000`.
 *
 * Integer centavos throughout. `quantity` is a 4-decimal string that never
 * becomes a float: it is parsed into ten-thousandths and folded into the
 * single division that rounds.
 */
final class InvoiceTotalsCalculator
{
    /** decimal(12,4) — the scale `quantity` is stored at. */
    private const QUANTITY_SCALE = 10_000;

    /**
     * Compute the totals for a set of lines.
     *
     * Each line's `taxRate` relation is read directly. Eager-load it — this
     * runs on the invoice show page and on every draft save, and an N+1 here
     * would be one query per line on documents that routinely carry dozens.
     *
     * @param  iterable<InvoiceLine>  $lines
     */
    public function calculate(iterable $lines, bool $isVatInclusive): InvoiceTotals
    {
        $vatable = 0;
        $exempt = 0;
        $zeroRated = 0;
        $vat = 0;
        $lineFigures = [];

        foreach ($lines as $line) {
            $rate = $line->taxRate;
            [$net, $tax] = $this->figuresFor($line, $rate, $isVatInclusive);

            $lineFigures[] = ['net' => $net->centavos(), 'tax' => $tax->centavos()];

            match ($this->bucketFor($rate)) {
                TaxRate::TYPE_EXEMPT => $exempt += $net->centavos(),
                TaxRate::TYPE_ZERO_RATED => $zeroRated += $net->centavos(),
                default => $vatable += $net->centavos(),
            };

            $vat += $tax->centavos();
        }

        return new InvoiceTotals($vatable, $exempt, $zeroRated, $vat, $lineFigures);
    }

    /**
     * Recalculate an invoice in place: write each line's stored net and tax,
     * then the header buckets.
     *
     * Neither the lines nor the invoice are saved — the caller owns the
     * transaction, so that a partially-recalculated invoice can never be
     * committed.
     *
     * @param  iterable<InvoiceLine>  $lines
     */
    public function applyTo(Invoice $invoice, iterable $lines): InvoiceTotals
    {
        $materialised = is_array($lines) ? $lines : iterator_to_array($lines);
        $totals = $this->calculate($materialised, (bool) $invoice->is_vat_inclusive);

        foreach (array_values($materialised) as $index => $line) {
            $line->line_net_centavos = $totals->lines[$index]['net'];
            $line->line_tax_centavos = $totals->lines[$index]['tax'];
        }

        $invoice->fill($totals->toHeaderAttributes());

        return $totals;
    }

    /**
     * The net and tax for one line.
     *
     * @return array{Money, Money}
     */
    private function figuresFor(InvoiceLine $line, ?TaxRate $rate, bool $isVatInclusive): array
    {
        $extended = $this->extendedAmount($line);

        // No rate, or a rate that posts no tax (exempt, zero-rated, 0 bps):
        // the extended amount is the net either way, and inclusive pricing
        // has nothing to strip out of it.
        if ($rate === null || ! $rate->postsTax()) {
            return [$extended, Money::zero()];
        }

        if ($isVatInclusive) {
            // The keyed price already contains the tax, so the net is what
            // is left after extracting it. Deriving the net by subtraction
            // rather than by a second division guarantees net + tax lands
            // back exactly on the price the operator typed.
            $tax = $rate->taxWithin($extended);

            return [$extended->minus($tax), $tax];
        }

        return [$extended, $rate->taxOn($extended)];
    }

    /**
     * `quantity × unit_price`, rounded once.
     *
     * The quantity is folded in as an integer count of ten-thousandths and
     * divided out in the same step, so there is exactly one rounding per
     * line and no float ever touches a money value.
     */
    private function extendedAmount(InvoiceLine $line): Money
    {
        $quantity = $this->quantityInTenThousandths((string) $line->quantity);

        return Money::fromCentavos((int) $line->unit_price_centavos)
            ->times($quantity)
            ->dividedBy(self::QUANTITY_SCALE);
    }

    /**
     * Parse a decimal(12,4) quantity into an integer count of
     * ten-thousandths, without going through a float.
     *
     * `(float) '0.0001' * 10000` is the kind of conversion that silently
     * yields 0.9999999 and then floors to 0. Parsing the string keeps the
     * value exact.
     */
    private function quantityInTenThousandths(string $quantity): int
    {
        $quantity = trim($quantity);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,4}))?$/', $quantity, $matches)) {
            throw new InvalidArgumentException(
                "Invoice line quantity '{$quantity}' is not a decimal with at most 4 places.",
            );
        }

        [, $sign, $whole, $fraction] = $matches + [3 => ''];

        $units = (int) $whole * self::QUANTITY_SCALE
            + (int) str_pad($fraction, 4, '0', STR_PAD_RIGHT);

        return $sign === '-' ? -$units : $units;
    }

    /**
     * Which sales bucket a line's net belongs in.
     *
     * Routed by the rate's declared *type*, not by whether it happens to
     * produce tax. A `vat_sales` rate configured at 0 bps therefore lands in
     * VATable sales with no tax, which looks wrong on the printed face — and
     * should, because it is a misconfigured rate. Quietly reclassifying it
     * as zero-rated would hide the mistake behind a plausible-looking
     * invoice.
     *
     * A line with no rate at all is treated as exempt: it is the reading
     * that under-claims rather than over-claims, and no VAT is charged
     * either way.
     */
    private function bucketFor(?TaxRate $rate): string
    {
        if ($rate === null) {
            return TaxRate::TYPE_EXEMPT;
        }

        return match ($rate->type) {
            TaxRate::TYPE_EXEMPT => TaxRate::TYPE_EXEMPT,
            TaxRate::TYPE_ZERO_RATED => TaxRate::TYPE_ZERO_RATED,
            default => TaxRate::TYPE_VAT_SALES,
        };
    }
}
