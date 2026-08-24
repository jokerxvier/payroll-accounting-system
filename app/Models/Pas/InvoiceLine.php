<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\Services\Accounting\InvoiceTotalsCalculator;
use App\ValueObjects\Money;
use Database\Factories\Pas\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One charge on an invoice.
 *
 * `line_net_centavos` and `line_tax_centavos` are stored, not derived. The
 * invoice total has to equal the sum of the lines a customer can add up on
 * the printed page, and a stored figure cannot drift when a tax rate is
 * edited later — an issued document shows the tax that was actually charged.
 *
 * @property int $id
 * @property int $school_id
 * @property int $invoice_id
 * @property int $line_number
 * @property string $description
 * @property string $quantity
 * @property int $unit_price_centavos
 * @property int $account_id
 * @property ?int $tax_rate_id
 * @property int $line_net_centavos
 * @property int $line_tax_centavos
 */
final class InvoiceLine extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    protected $table = 'pas_invoice_lines';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'invoice_id',
        'line_number',
        'description',
        'quantity',
        'unit_price_centavos',
        'account_id',
        'tax_rate_id',
        'line_net_centavos',
        'line_tax_centavos',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return InvoiceLineFactory::new();
    }

    /**
     * `quantity` is deliberately absent from the casts: it stays the decimal
     * string the database returns. Casting it to float would hand the
     * calculator exactly the value it must never multiply money by.
     * {@see InvoiceTotalsCalculator} converts it to
     * an integer number of ten-thousandths instead.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'invoice_id' => 'integer',
            'line_number' => 'integer',
            'unit_price_centavos' => 'integer',
            'account_id' => 'integer',
            'tax_rate_id' => 'integer',
            'line_net_centavos' => 'integer',
            'line_tax_centavos' => 'integer',
        ];
    }

    public function net(): Money
    {
        return Money::fromCentavos($this->line_net_centavos);
    }

    public function tax(): Money
    {
        return Money::fromCentavos($this->line_tax_centavos);
    }

    /** What this line adds to the invoice total. */
    public function gross(): Money
    {
        return Money::fromCentavos($this->line_net_centavos + $this->line_tax_centavos);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }
}
