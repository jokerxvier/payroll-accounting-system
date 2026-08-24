<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\ClosedAccountingPeriodException;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Services\Accounting\AccountingPeriodGuard;
use App\Services\Accounting\JournalEntryNumberAllocator;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Reverses a posted entry by posting its mirror image.
 *
 * The original is not touched: its figures, its lines, its status, and its
 * posting stamps all stay exactly as they were
 * (`rules/CODING_STANDARDS_LARAVEL.md` §471). Editing or deleting a posted
 * entry would rewrite history that has already been reported on; posting the
 * reverse leaves both the mistake and the correction visible, which is what
 * an auditor needs to follow.
 *
 * Both entries stay `posted`, and that is load-bearing. They offset to zero,
 * so a report reading posted entries sees the correction happen. Marking the
 * original `voided` would drop it from `JournalEntry::scopePosted()` and
 * leave the reversal unmatched — the account would then be wrong by the full
 * amount, in the opposite direction. The original carries `reversed_at` /
 * `reversed_by_user_id` instead, which record who corrected it without
 * changing a single figure.
 *
 * The reversal is a real entry: it carries its own number, goes through the
 * same balance and period checks as any other post, and lands in whichever
 * period covers its own date. That last point matters — if the original's
 * period has since closed, the correction belongs in an open one, and
 * `$reversalDate` is how the caller says which.
 *
 * Each reversing line swaps sides: a debit of X becomes a credit of X. A
 * balanced entry mirrored line-for-line is still balanced, so the reversal
 * can never fail the invariant the original passed.
 */
final class ReverseJournalEntry
{
    public function __construct(
        private readonly PostJournalEntry $poster,
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly JournalEntryNumberAllocator $numbers,
    ) {}

    /**
     * @param  ?CarbonImmutable  $reversalDate  Date to post the reversal on.
     *                                          Defaults to the original's date,
     *                                          which is right while that period
     *                                          is still open and rejected by the
     *                                          period guard when it is not.
     * @return JournalEntry The reversing entry.
     *
     * @throws DomainException Not posted, or already reversed.
     * @throws ClosedAccountingPeriodException Target period closed.
     */
    public function execute(
        JournalEntry $entry,
        int $actorUserId,
        ?CarbonImmutable $reversalDate = null,
        ?string $reason = null,
    ): JournalEntry {
        if (! $entry->isReversible()) {
            throw new DomainException(sprintf(
                'Cannot reverse journal entry [%s] (status [%s]%s). Only a posted entry can be reversed, and only once.',
                $entry->entry_number ?? (string) $entry->getKey(),
                $entry->status,
                $entry->hasBeenReversed() ? ', already reversed' : '',
            ));
        }

        $date = $reversalDate ?? $entry->date;

        return DB::transaction(function () use ($entry, $actorUserId, $date, $reason): JournalEntry {
            // Fail before writing anything if the target period will not
            // take the reversal.
            $this->periodGuard->resolveOpenPeriodFor($date);

            $reversal = JournalEntry::create([
                'school_id' => $entry->school_id,
                'entry_number' => $this->numbers->allocate($date),
                'date' => $date,
                'reference' => $entry->reference,
                'narration' => $reason !== null && $reason !== ''
                    ? sprintf('Reversal of %s — %s', $entry->entry_number, $reason)
                    : sprintf('Reversal of %s', $entry->entry_number),
                'status' => JournalEntry::STATUS_DRAFT,
                // Carried over so the reversal traces back to the same
                // document the original came from.
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'reversal_of_entry_id' => $entry->getKey(),
            ]);

            foreach ($entry->lines()->get() as $line) {
                JournalEntryLine::create([
                    'school_id' => $line->school_id,
                    'journal_entry_id' => $reversal->getKey(),
                    'line_number' => $line->line_number,
                    'account_id' => $line->account_id,
                    // The swap. Mirroring a balanced entry keeps it balanced.
                    'debit_centavos' => $line->credit_centavos,
                    'credit_centavos' => $line->debit_centavos,
                    'description' => $line->description,
                ]);
            }

            $posted = $this->poster->execute($reversal->fresh(), $actorUserId);

            // The original STAYS posted. Only the stamps are added, and
            // they change no figure — the pair offsets to zero in every
            // report that reads posted entries. Marking it voided here would
            // drop it from `scopePosted()` and leave the reversal
            // unmatched, understating the account by the full amount.
            $entry->forceFill([
                'reversed_at' => now(),
                'reversed_by_user_id' => $actorUserId,
            ])->save();

            return $posted;
        });
    }
}
