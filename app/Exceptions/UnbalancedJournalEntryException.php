<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\ValueObjects\Money;
use DomainException;

/**
 * Thrown when a journal entry's debits do not equal its credits.
 *
 * The single most important invariant in the module: an unbalanced entry
 * would silently misstate every report derived from the ledger, and because
 * reports aggregate, the error would surface far from its cause. So it is
 * refused at write time rather than detected later
 * (`rules/CODING_STANDARDS_LARAVEL.md` §406).
 *
 * The message carries both totals and the difference in pesos, because the
 * difference is what tells an operator what they mistyped.
 */
final class UnbalancedJournalEntryException extends DomainException
{
    public static function forTotals(Money $debits, Money $credits): self
    {
        $difference = $debits->minus($credits);

        return new self(sprintf(
            'Journal entry does not balance: debits %s, credits %s, off by %s. Every entry must have total debits exactly equal to total credits.',
            $debits->toDecimalString(),
            $credits->toDecimalString(),
            $difference->toDecimalString(),
        ));
    }
}
