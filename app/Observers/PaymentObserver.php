<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pas\Payment;

/**
 * Keeps a payment's allocations auditable when the payment is deleted.
 *
 * `pas_payment_allocations.payment_id` is `cascadeOnDelete`, so the database
 * would remove the allocations without Eloquent firing
 * `PaymentAllocation::deleted` and the AuditObserver would never see them.
 * The trail would show the payment disappearing and nothing about which
 * invoices it had settled.
 *
 * Only drafts are ever deletable — PaymentPolicy refuses the rest, and a
 * posted payment is undone by voiding so its ledger entry can be reversed.
 *
 * Same trap as {@see InvoiceObserver} and {@see JournalEntryObserver}.
 */
final class PaymentObserver
{
    public function deleting(Payment $payment): void
    {
        foreach ($payment->allocations()->get() as $allocation) {
            $allocation->delete();
        }
    }
}
