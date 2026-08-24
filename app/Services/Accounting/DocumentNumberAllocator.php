<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\DocumentNumberUnavailableException;
use App\Models\Pas\DocumentNumberSeries;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issues controlled document numbers.
 *
 * The whole point of this class is that the number and the document are
 * created together or not at all. `allocate()` therefore REFUSES to run
 * outside a transaction: taking a number, committing the increment, and then
 * failing to insert the document would burn a serial the Bureau expects to
 * see on a real form.
 *
 * That is the difference from {@see JournalEntryNumberAllocator}, which
 * tolerates gaps because a journal reference is internal. Here a gap in an
 * ATP-issued range is an audit finding, so the guarantee has to be
 * structural rather than a convention callers remember.
 *
 * Concurrency: the series row is taken with `lockForUpdate()`, so a second
 * request wanting the same series waits for the first to commit or roll back.
 * MySQL takes a real row lock; sqlite ignores the hint but runs the test
 * suite single-threaded, so behaviour under test matches.
 */
final class DocumentNumberAllocator
{
    /**
     * Take the next number for `$documentType` and advance the series.
     *
     * Returns the formatted number ("SI-000042"), having already incremented
     * the counter. Both effects live in the caller's transaction, so a
     * rollback returns the number to the pool.
     *
     * @throws LogicException Called outside a transaction.
     * @throws DocumentNumberUnavailableException No series, inactive, or range exhausted.
     */
    public function allocate(string $documentType): string
    {
        if (DB::transactionLevel() === 0) {
            // A number issued outside a transaction cannot be given back.
            throw new LogicException(
                'DocumentNumberAllocator::allocate() must run inside a transaction, so that a failed document returns its number instead of burning it.'
            );
        }

        $series = DocumentNumberSeries::query()
            ->where('document_type', $documentType)
            ->lockForUpdate()
            ->first();

        if ($series === null) {
            throw DocumentNumberUnavailableException::noSeries($documentType);
        }

        if (! $series->is_active) {
            throw DocumentNumberUnavailableException::inactive($series);
        }

        $number = $series->next_number;

        if (! $series->isWithinAuthorisedRange($number)) {
            throw DocumentNumberUnavailableException::rangeExhausted($series);
        }

        $series->forceFill(['next_number' => $number + 1])->save();

        return $series->format($number);
    }

    /**
     * What the next number would be, without taking it.
     *
     * For showing an operator the number a draft will receive. Never use this
     * to set a document number — between the preview and the save, another
     * request may have taken it.
     */
    public function peek(string $documentType): ?string
    {
        $series = DocumentNumberSeries::query()
            ->where('document_type', $documentType)
            ->first();

        if ($series === null || ! $series->is_active) {
            return null;
        }

        return $series->isWithinAuthorisedRange($series->next_number)
            ? $series->format($series->next_number)
            : null;
    }
}
