<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    /**
     * Default: one unit at ₱1,000.00 with no tax rate and no computed
     * figures.
     *
     * `line_net_centavos` and `line_tax_centavos` are left at zero because
     * they are the calculator's output. A factory that filled them in would
     * make it impossible to tell whether a passing test had run the
     * calculator at all.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'line_number' => 1,
            'description' => fake()->sentence(3),
            'quantity' => '1.0000',
            'unit_price_centavos' => 100_000,
            'account_id' => ChartOfAccount::factory(),
            'tax_rate_id' => null,
            'line_net_centavos' => 0,
            'line_tax_centavos' => 0,
        ];
    }

    public function forInvoice(Invoice $invoice): self
    {
        return $this->state(fn (): array => [
            'invoice_id' => $invoice->getKey(),
            'school_id' => $invoice->school_id,
        ]);
    }

    public function taxedAt(TaxRate $rate): self
    {
        return $this->state(fn (): array => ['tax_rate_id' => $rate->getKey()]);
    }

    /** @param  numeric-string|int|float  $quantity */
    public function quantity(string|int|float $quantity): self
    {
        return $this->state(fn (): array => [
            'quantity' => number_format((float) $quantity, 4, '.', ''),
        ]);
    }

    public function unitPrice(int $centavos): self
    {
        return $this->state(fn (): array => ['unit_price_centavos' => $centavos]);
    }
}
