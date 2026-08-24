<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\Invoice;
use App\ValueObjects\Money;

/**
 * The computed face of an invoice: the three BIR sales buckets, the tax, and
 * the per-line figures that add up to them.
 *
 * Immutable and detached from Eloquent so the calculator can be exercised —
 * and a total previewed for an unsaved draft — without touching the
 * database.
 *
 * `lines` is indexed by the position of the line in the input, not by line
 * id, because the common caller is a form submission whose lines do not
 * exist yet.
 */
final readonly class InvoiceTotals
{
    /**
     * @param  list<array{net: int, tax: int}>  $lines
     */
    public function __construct(
        public int $vatableSalesCentavos,
        public int $vatExemptSalesCentavos,
        public int $zeroRatedSalesCentavos,
        public int $vatCentavos,
        public array $lines,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, []);
    }

    /**
     * The three nets plus the tax.
     *
     * Derived rather than stored so it cannot disagree with its parts: the
     * invariant `total = vatable + exempt + zero_rated + vat` holds by
     * construction here, and {@see Invoice::totalsAreConsistent()}
     * re-checks it after the figures have made the round trip through the
     * database.
     */
    public function totalCentavos(): int
    {
        return $this->vatableSalesCentavos
            + $this->vatExemptSalesCentavos
            + $this->zeroRatedSalesCentavos
            + $this->vatCentavos;
    }

    public function total(): Money
    {
        return Money::fromCentavos($this->totalCentavos());
    }

    /** Every net, regardless of VAT treatment. */
    public function netCentavos(): int
    {
        return $this->vatableSalesCentavos
            + $this->vatExemptSalesCentavos
            + $this->zeroRatedSalesCentavos;
    }

    /**
     * The header columns, ready to fill onto an Invoice.
     *
     * @return array{
     *     vatable_sales_centavos: int,
     *     vat_exempt_sales_centavos: int,
     *     zero_rated_sales_centavos: int,
     *     vat_centavos: int,
     *     total_centavos: int,
     * }
     */
    public function toHeaderAttributes(): array
    {
        return [
            'vatable_sales_centavos' => $this->vatableSalesCentavos,
            'vat_exempt_sales_centavos' => $this->vatExemptSalesCentavos,
            'zero_rated_sales_centavos' => $this->zeroRatedSalesCentavos,
            'vat_centavos' => $this->vatCentavos,
            'total_centavos' => $this->totalCentavos(),
        ];
    }
}
