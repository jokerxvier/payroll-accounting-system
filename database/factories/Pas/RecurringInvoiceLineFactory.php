<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\RecurringInvoice;
use App\Models\Pas\RecurringInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoiceLine>
 */
class RecurringInvoiceLineFactory extends Factory
{
    protected $model = RecurringInvoiceLine::class;

    /**
     * Default: one unit at ₱1,000.00, untaxed.
     *
     * No computed net or tax here, unlike an invoice line — a template has
     * none, and that is the distinction the model exists to hold.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recurring_invoice_id' => RecurringInvoice::factory(),
            'line_number' => 1,
            'description' => 'Tuition fee',
            'quantity' => '1.0000',
            'unit_price_centavos' => 100_000,
            'account_id' => ChartOfAccount::factory(),
            'tax_rate_id' => null,
        ];
    }
}
