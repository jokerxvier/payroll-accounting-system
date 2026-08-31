<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\ClosedAccountingPeriodException;
use App\Models\Pas\Payment;
use App\Services\Accounting\InvoiceBalanceService;
use App\Services\Accounting\PaymentPostingService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * `draft → posted`. The moment a payment becomes real.
 *
 * Two things happen together or not at all: the payment reaches the ledger,
 * and every invoice it was allocated against has its balance recomputed.
 *
 * The order matters. The status flips first, because
 * {@see InvoiceBalanceService} counts allocations only from *posted*
 * payments — recomputing before the flip would find nothing and leave every
 * invoice reading unpaid immediately after the payment that settled them.
 *
 * A ledger failure fails the whole thing, as it does for invoice approval.
 * Payroll deliberately swallows one — staff still have to be paid — but there
 * is no equivalent reason to record money as received while the books reject
 * it.
 */
final class PostPayment
{
    public function __construct(
        private readonly PaymentPostingService $poster,
        private readonly InvoiceBalanceService $balances,
    ) {}

    /**
     * @throws DomainException Illegal status, or a payment of nothing.
     * @throws ClosedAccountingPeriodException The payment date falls in a closed period.
     */
    public function execute(Payment $payment, ?int $actorUserId): Payment
    {
        if (! $payment->isDraft()) {
            throw new DomainException(sprintf(
                'Cannot post payment #%d from status [%s]. Only a draft can be posted.',
                $payment->getKey(),
                $payment->status,
            ));
        }

        if ($payment->amount_centavos <= 0) {
            throw new DomainException(sprintf(
                'Payment #%d moves no money. Enter an amount before posting it.',
                $payment->getKey(),
            ));
        }

        return DB::transaction(function () use ($payment, $actorUserId): Payment {
            $payment->forceFill([
                'status' => Payment::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => $actorUserId,
            ])->save();

            $this->poster->post($payment, $actorUserId);

            // After the flip — see the class docblock.
            $this->balances->recomputeFor($payment);

            return $payment->refresh();
        });
    }
}
