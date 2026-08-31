<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use Database\Factories\Pas\RecurringInvoiceLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One templated charge on a schedule.
 *
 * Carries no `line_net` / `line_tax`, unlike {@see InvoiceLine}. Those are
 * computed at generation from whatever the tax rate says that day: a schedule
 * that stored them would keep billing last year's VAT after a rate changed,
 * and the point of an invoice line storing its tax is to freeze what was
 * actually charged on a document — which a template is not.
 *
 * @property int $id
 * @property int $school_id
 * @property int $recurring_invoice_id
 * @property int $line_number
 * @property string $description
 * @property string $quantity
 * @property int $unit_price_centavos
 * @property int $account_id
 * @property ?int $tax_rate_id
 * @property-read ?ChartOfAccount $account
 * @property-read ?TaxRate $taxRate
 */
final class RecurringInvoiceLine extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<RecurringInvoiceLineFactory> */
    use HasFactory;

    protected $table = 'pas_recurring_invoice_lines';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'recurring_invoice_id',
        'line_number',
        'description',
        'quantity',
        'unit_price_centavos',
        'account_id',
        'tax_rate_id',
    ];

    /**
     * `quantity` is deliberately absent: it stays the decimal string the
     * database returns, so nothing multiplies money by a float.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recurring_invoice_id' => 'integer',
            'line_number' => 'integer',
            'unit_price_centavos' => 'integer',
            'account_id' => 'integer',
            'tax_rate_id' => 'integer',
        ];
    }

    /** @return BelongsTo<RecurringInvoice, $this> */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
