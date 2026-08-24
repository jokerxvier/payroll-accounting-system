<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\Services\Accounting\InvoiceBalanceService;
use App\ValueObjects\Money;
use Database\Factories\Pas\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one payment was applied to one invoice.
 *
 * Kept even when the payment is voided. The row is the record of what was
 * applied and to what; it stops affecting anything on its own, because
 * {@see InvoiceBalanceService} only sums allocations
 * belonging to posted payments. Undoing a payment therefore deletes nothing.
 *
 * @property int $id
 * @property int $school_id
 * @property int $payment_id
 * @property int $invoice_id
 * @property int $amount_centavos
 */
final class PaymentAllocation extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    protected $table = 'pas_payment_allocations';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'payment_id',
        'invoice_id',
        'amount_centavos',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return PaymentAllocationFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'payment_id' => 'integer',
            'invoice_id' => 'integer',
            'amount_centavos' => 'integer',
        ];
    }

    public function amount(): Money
    {
        return Money::fromCentavos($this->amount_centavos);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
