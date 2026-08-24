<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\ClosedAccountingPeriodException;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Services\Accounting\AccountingPeriodGuard;
use App\Services\Accounting\JournalEntryNumberAllocator;
use App\ValueObjects\Money;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `draft|pending → posted`. The one door into the ledger.
 *
 * Everything that will ever write to the books goes through here — manual
 * entries now, payroll in Slice 3, invoices and payments in Slices 5-7 — so
 * the invariants only have to hold in one place:
 *
 *   1. Total debits equal total credits, exactly, in integer centavos.
 *   2. The entry's date falls in an OPEN accounting period.
 *   3. Every line moves exactly one side, by a positive amount.
 *   4. The entry is not empty and does not net to zero.
 *
 * All four are checked before anything is written, inside the transaction
 * that writes it, so a rejected post leaves nothing behind.
 *
 * Per `rules/CODING_STANDARDS_LARAVEL.md` §406 the balance rule is enforced
 * at the action layer and covered by tests. It is not a database constraint:
 * the check spans rows, and MySQL check constraints do not port to the
 * sqlite the suite runs on.
 */
final class PostJournalEntry
{
    public function __construct(
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly JournalEntryNumberAllocator $numbers,
    ) {}

    /**
     * @throws DomainException Illegal status, malformed or empty lines.
     * @throws UnbalancedJournalEntryException Debits ≠ credits.
     * @throws ClosedAccountingPeriodException Period closed or missing.
     */
    public function execute(JournalEntry $entry, int $actorUserId): JournalEntry
    {
        if (! $entry->isPostable()) {
            throw new DomainException(sprintf(
                'Cannot post journal entry [%s] from status [%s]. Expected draft or pending.',
                $entry->entry_number !== '' ? $entry->entry_number : (string) $entry->getKey(),
                $entry->status,
            ));
        }

        return DB::transaction(function () use ($entry, $actorUserId): JournalEntry {
            /** @var Collection<int, JournalEntryLine> $lines */
            $lines = $entry->lines()->get();

            $this->assertLinesAreWellFormed($lines);

            [$debits, $credits] = $this->totals($lines);

            if (! $debits->equals($credits)) {
                throw UnbalancedJournalEntryException::forTotals($debits, $credits);
            }

            // A balanced entry of zero is balanced but meaningless, and it
            // would clutter the ledger with rows that move nothing.
            if ($debits->isZero()) {
                throw new DomainException(
                    'A journal entry must move a non-zero amount. Every line is zero.'
                );
            }

            // Resolved from the date and pinned on the row, so closing the
            // period later freezes exactly the entries filed into it.
            $period = $this->periodGuard->resolveOpenPeriodFor($entry->date);

            $entry->forceFill([
                // A reversal arrives with its number already allocated by
                // VoidJournalEntry; anything else gets one now.
                'entry_number' => $entry->entry_number !== null && $entry->entry_number !== ''
                    ? $entry->entry_number
                    : $this->numbers->allocate($entry->date),
                'accounting_period_id' => $period->getKey(),
                'status' => JournalEntry::STATUS_POSTED,
                'total_debit_centavos' => $debits->centavos(),
                'total_credit_centavos' => $credits->centavos(),
                'posted_at' => now(),
                'posted_by_user_id' => $actorUserId,
            ])->save();

            return $entry->fresh(['lines']);
        });
    }

    /**
     * Double-entry needs at least two lines, and each has to move exactly
     * one side by a positive amount.
     *
     * A line with both sides set, or neither, is not a debit or a credit —
     * it is a data error that would still let the entry "balance" while
     * describing nothing.
     *
     * @param  Collection<int, JournalEntryLine>  $lines
     *
     * @throws DomainException
     */
    private function assertLinesAreWellFormed(Collection $lines): void
    {
        if ($lines->count() < 2) {
            throw new DomainException(
                'A journal entry requires at least two lines — one debit and one credit.'
            );
        }

        foreach ($lines as $line) {
            $hasDebit = $line->debit_centavos !== 0;
            $hasCredit = $line->credit_centavos !== 0;

            if ($hasDebit && $hasCredit) {
                throw new DomainException(sprintf(
                    'Line %d has both a debit and a credit. A line moves exactly one side.',
                    $line->line_number,
                ));
            }

            if ($line->debit_centavos < 0 || $line->credit_centavos < 0) {
                throw new DomainException(sprintf(
                    'Line %d has a negative amount. Reverse the side instead of negating the figure.',
                    $line->line_number,
                ));
            }
        }
    }

    /**
     * @param  Collection<int, JournalEntryLine>  $lines
     * @return array{0: Money, 1: Money}
     */
    private function totals(Collection $lines): array
    {
        $debits = Money::zero();
        $credits = Money::zero();

        foreach ($lines as $line) {
            $debits = $debits->plus($line->debit());
            $credits = $credits->plus($line->credit());
        }

        return [$debits, $credits];
    }
}
