<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Actions\Accounting\PostJournalEntry;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\Payment;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a payment to the general ledger.
 *
 * A receipt:
 *
 *   DR  cash / bank account              amount
 *     CR  AR control (contact override)    allocated
 *     CR  Advances from Customers          amount − allocated   (omitted at 0)
 *
 * A disbursement is the mirror image: the allocated part debits the payable,
 * any excess debits Advances to Suppliers, and the whole amount credits cash.
 *
 * The advances line is what makes an overpayment honest. Crediting the full
 * amount to the receivable would drive a customer's balance negative and call
 * a liability an asset owed backwards; money received against no invoice is
 * something the school still owes in goods, and it belongs on its own line
 * where a balance sheet can show it.
 *
 * Idempotent on `journal_entry_id`, as invoice and payroll posting are. A
 * retried job or a double-clicked button would otherwise double both the cash
 * and the receivable.
 */
final class PaymentPostingService
{
    public function __construct(
        private readonly PostJournalEntry $poster,
        private readonly ControlAccountResolver $controlAccounts,
    ) {}

    /**
     * @throws DomainException Nothing to post, or the figures disagree.
     * @throws RuntimeException A required system account is missing.
     */
    public function post(Payment $payment, int $actorUserId): JournalEntry
    {
        if ($payment->journal_entry_id !== null) {
            $existing = JournalEntry::query()->find($payment->journal_entry_id);

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($payment->amount_centavos <= 0) {
            throw new DomainException(sprintf(
                'Payment #%d moves no money, so there is nothing to post.',
                $payment->getKey(),
            ));
        }

        if ($payment->allocated_centavos > $payment->amount_centavos) {
            // Belt and braces: ApplyPaymentAllocations refuses this already.
            // Reaching it here would mean the two disagree, which is worth
            // failing loudly over rather than posting a lopsided entry.
            throw new DomainException(sprintf(
                'Payment #%d has %s allocated against an amount of %s.',
                $payment->getKey(),
                $payment->allocated()->toDecimalString(),
                $payment->amount()->toDecimalString(),
            ));
        }

        $isReceipt = $payment->isReceipt();
        $allocated = $payment->allocated_centavos;
        $advance = $payment->amount_centavos - $allocated;

        return DB::transaction(function () use (
            $payment,
            $actorUserId,
            $isReceipt,
            $allocated,
            $advance,
        ): JournalEntry {
            $entry = JournalEntry::create([
                'date' => $payment->payment_date,
                'reference' => $payment->reference ?? sprintf('PAY-%d', $payment->getKey()),
                'narration' => $this->narration($payment),
                'status' => JournalEntry::STATUS_DRAFT,
                // The forward trace: a posted figure walks back to the
                // payment that caused it.
                'source_type' => Payment::class,
                'source_id' => $payment->getKey(),
            ]);

            $lineNumber = 1;
            $description = $this->narration($payment);

            // Cash moves by the full amount, in or out.
            JournalEntryLine::create([
                'journal_entry_id' => $entry->getKey(),
                'line_number' => $lineNumber++,
                'account_id' => $payment->cash_account_id,
                'debit_centavos' => $isReceipt ? $payment->amount_centavos : 0,
                'credit_centavos' => $isReceipt ? 0 : $payment->amount_centavos,
                'description' => $description,
            ]);

            if ($allocated > 0) {
                $control = $this->controlAccounts->resolve($payment->contact, $isReceipt);

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->getKey(),
                    'line_number' => $lineNumber++,
                    'account_id' => $control->getKey(),
                    'debit_centavos' => $isReceipt ? 0 : $allocated,
                    'credit_centavos' => $isReceipt ? $allocated : 0,
                    'description' => $description,
                ]);
            }

            if ($advance > 0) {
                $advanceAccount = $this->controlAccounts->systemAccount(
                    $isReceipt
                        ? ChartOfAccount::SYSTEM_CUSTOMER_ADVANCES
                        : ChartOfAccount::SYSTEM_SUPPLIER_ADVANCES,
                );

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->getKey(),
                    'line_number' => $lineNumber++,
                    'account_id' => $advanceAccount->getKey(),
                    'debit_centavos' => $isReceipt ? 0 : $advance,
                    'credit_centavos' => $isReceipt ? $advance : 0,
                    'description' => $description,
                ]);
            }

            $posted = $this->poster->execute($entry->fresh(), $actorUserId);

            $payment->forceFill(['journal_entry_id' => $posted->getKey()])->save();

            return $posted;
        });
    }

    public function hasPosted(Payment $payment): bool
    {
        return $payment->journal_entry_id !== null;
    }

    private function narration(Payment $payment): string
    {
        return sprintf(
            '%s #%d — %s',
            $payment->isReceipt() ? 'Receipt' : 'Disbursement',
            $payment->getKey(),
            $payment->contact?->name ?? 'Unknown contact',
        );
    }
}
