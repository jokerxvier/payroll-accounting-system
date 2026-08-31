<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Accounting\ApplyPaymentAllocations;
use App\Actions\Accounting\PostPayment;
use App\Models\Pas\Invoice;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentGatewaySetting;
use App\Services\Accounting\InvoiceBalanceService;
use App\Services\Payments\Data\GatewayEvent;
use App\Services\Payments\GatewayAccountResolver;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a verified gateway event into a posted receipt.
 *
 * This action **composes** the Slice 7 path rather than reimplementing it.
 * Everything that makes a manually keyed payment correct — the allocation
 * bounds, the status ordering, the ledger posting, the balance recompute —
 * already lives in {@see ApplyPaymentAllocations} and {@see PostPayment}, and
 * a second path that wrote `pas_payments` directly would be a second set of
 * invariants to keep in step. There is one way to settle an invoice; this is
 * a different way of *starting* it, not a different way of doing it.
 *
 * Two things it does that a human path does not:
 *
 *   1. **It allocates what it can, not what it was given.** A customer can
 *      pay a link twice, or pay after someone recorded a bank transfer for
 *      the same invoice. The allocation is capped at what is still
 *      outstanding, and any excess becomes an advance — which is the same
 *      treatment an over-payment gets today, and better than refusing money
 *      that has already left the customer's account.
 *
 *   2. **It posts with no actor.** Nobody clicked anything, so
 *      `posted_by_user_id` is null rather than attributed to whoever happened
 *      to configure the gateway.
 */
final class RecordGatewayPayment
{
    public function __construct(
        private readonly ApplyPaymentAllocations $allocator,
        private readonly PostPayment $poster,
        private readonly InvoiceBalanceService $balances,
        private readonly GatewayAccountResolver $accounts,
    ) {}

    /**
     * @throws DomainException When the event cannot be turned into a receipt.
     */
    public function execute(
        PaymentGatewaySetting $setting,
        GatewayEvent $event,
        Invoice $invoice,
    ): Payment {
        if (! $event->isPaid) {
            throw new DomainException(sprintf(
                'Event [%s] does not represent a completed payment.',
                $event->type,
            ));
        }

        if ($event->grossCentavos <= 0) {
            throw new DomainException('A gateway payment of zero cannot be recorded.');
        }

        if (! $invoice->isIssued()) {
            throw new DomainException(sprintf(
                'Invoice %s is not in an issued state, so it cannot be settled.',
                $invoice->number ?? ('#'.$invoice->getKey()),
            ));
        }

        return DB::transaction(function () use ($setting, $event, $invoice): Payment {
            $payment = Payment::create([
                'type' => Payment::TYPE_RECEIPT,
                'contact_id' => $invoice->contact_id,
                // Today, not the invoice's issue date: this is when the money
                // moved, and the period guard reads this date.
                'payment_date' => now()->toImmutable()->startOfDay(),
                // GROSS. The fee is split out at posting; the receivable is
                // settled by what the customer actually paid.
                'amount_centavos' => $event->grossCentavos,
                'fee_centavos' => $event->feeCentavos,
                // The RESOLVED ids, not the setting's columns.
                //
                // `pas_payments.cash_account_id` is NOT NULL, so a blank
                // override cannot be copied through — and more importantly the
                // Payment is the reproducibility record. Settings are mutable
                // configuration; resolving here means a reversal months later
                // reproduces the entry that actually posted, rather than
                // whatever the settings happen to say by then.
                'fee_account_id' => $this->accounts->resolveFee($setting)->getKey(),
                'cash_account_id' => $this->accounts->resolveCash($setting)->getKey(),
                'method' => Payment::METHOD_ONLINE,
                'gateway_provider' => $setting->provider,
                'gateway_reference' => $event->paymentReference,
                'reference' => $event->paymentReference,
                'status' => Payment::STATUS_DRAFT,
            ]);

            // Capped at what is still outstanding. Paying a link twice, or
            // paying one already settled at the counter, must not push the
            // receivable negative — the surplus becomes an advance, which is
            // exactly how an over-payment is handled everywhere else.
            $outstanding = $this->balances->remainingCentavosFor($invoice);
            $allocate = min($event->grossCentavos, max($outstanding, 0));

            if ($allocate > 0) {
                $this->allocator->execute($payment, [[
                    'invoice_id' => $invoice->getKey(),
                    'amount_centavos' => $allocate,
                ]]);
            }

            // Null actor: no user posted this. `posted_by_user_id` is
            // nullable precisely so this stays honest.
            return $this->poster->execute($payment->refresh(), null);
        });
    }
}
