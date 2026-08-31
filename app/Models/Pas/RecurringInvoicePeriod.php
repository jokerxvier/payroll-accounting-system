<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that one schedule has already billed one period.
 *
 * The claim, not the invoice, is what stops a family being charged twice —
 * `pas_recinvp_schedule_period_unq` makes a second attempt a database error
 * rather than a second document. It is held here rather than on the invoice
 * because it has to outlive the invoice: deleting a wrongly-generated draft
 * must not hand the period back to tonight's run, and voiding an issued
 * invoice must not consume the period for good.
 *
 * `invoice_id` is therefore nullable and `nullOnDelete`. The claim is the
 * point; the invoice is only the evidence.
 *
 * @property int $id
 * @property int $school_id
 * @property int $recurring_invoice_id
 * @property string $period
 * @property ?int $invoice_id
 * @property ?string $note
 * @property ?CarbonImmutable $claimed_at
 * @property-read ?Invoice $invoice
 */
final class RecurringInvoicePeriod extends Model
{
    use Auditable;
    use BelongsToTenant;

    protected $table = 'pas_recurring_invoice_periods';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'recurring_invoice_id',
        'period',
        'invoice_id',
        'note',
        'claimed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recurring_invoice_id' => 'integer',
            'invoice_id' => 'integer',
            'claimed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<RecurringInvoice, $this> */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
