<?php

declare(strict_types=1);

use App\Exceptions\DocumentNumberUnavailableException;
use App\Models\Pas\DocumentNumberSeries;
use App\Services\Accounting\DocumentNumberAllocator;
use Illuminate\Support\Facades\DB;

/*
 * DocumentNumberAllocator — controlled document numbering.
 *
 * A BIR-controlled serial is not the same animal as a journal reference. The
 * Bureau issues an Authority To Print covering a specific range, and a gap in
 * an issued range is an audit finding. So unlike JournalEntryNumberAllocator,
 * which tolerates gaps, this one has to guarantee that a number and its
 * document are created together or not at all.
 */

beforeEach(function (): void {
    DocumentNumberSeries::query()->withoutGlobalScopes()->delete();
});

function allocator(): DocumentNumberAllocator
{
    return app(DocumentNumberAllocator::class);
}

/* ── The gapless guarantee ──────────────────────────────────────────── */

/*
 * The "refuses outside a transaction" guard is exercised in
 * tests/Unit/Services/DocumentNumberAllocatorGuardTest.php, not here.
 * RefreshDatabase wraps every Feature test in a transaction, so
 * DB::transactionLevel() is never 0 in this file and the guard can never
 * fire — it would pass vacuously.
 */

it('returns the number when the transaction rolls back', function () {
    // The behavioural half. This is the test that matters: a failed document
    // must not burn a serial.
    DocumentNumberSeries::factory()->create(['next_number' => 1]);

    try {
        DB::transaction(function (): void {
            allocator()->allocate(DocumentNumberSeries::TYPE_SALES_INVOICE);

            throw new RuntimeException('document insert failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DocumentNumberSeries::query()->first()->next_number)->toBe(1);

    // And the next successful allocation gets the number that was almost
    // used, rather than the one after it.
    $number = DB::transaction(fn (): string => allocator()->allocate(
        DocumentNumberSeries::TYPE_SALES_INVOICE,
    ));

    expect($number)->toBe('SI-000001');
});

it('issues sequential numbers and advances the series', function () {
    DocumentNumberSeries::factory()->create(['next_number' => 1]);

    $numbers = [];

    for ($i = 0; $i < 3; $i++) {
        $numbers[] = DB::transaction(fn (): string => allocator()->allocate(
            DocumentNumberSeries::TYPE_SALES_INVOICE,
        ));
    }

    expect($numbers)->toBe(['SI-000001', 'SI-000002', 'SI-000003'])
        ->and(DocumentNumberSeries::query()->first()->next_number)->toBe(4);
});

it('formats with the series prefix and padding', function () {
    DocumentNumberSeries::factory()->create([
        'prefix' => 'INV/2026/',
        'padding' => 4,
        'next_number' => 42,
    ]);

    $number = DB::transaction(fn (): string => allocator()->allocate(
        DocumentNumberSeries::TYPE_SALES_INVOICE,
    ));

    expect($number)->toBe('INV/2026/0042');
});

/* ── Authority To Print bounds ──────────────────────────────────────── */

it('issues within an authorised range', function () {
    DocumentNumberSeries::factory()->withAuthority(100, 200)->create();

    $number = DB::transaction(fn (): string => allocator()->allocate(
        DocumentNumberSeries::TYPE_SALES_INVOICE,
    ));

    expect($number)->toBe('SI-000100');
});

it('refuses to issue past the authorised range', function () {
    // Issuing past the end would put numbers on real documents that the
    // Bureau never authorised — a hard refusal, not a warning.
    $series = DocumentNumberSeries::factory()->nearlyExhausted()->create();

    // The last authorised number still issues.
    $last = DB::transaction(fn (): string => allocator()->allocate(
        DocumentNumberSeries::TYPE_SALES_INVOICE,
    ));
    expect($last)->toBe('SI-000010');

    // The next does not.
    expect(fn () => DB::transaction(fn () => allocator()->allocate(
        DocumentNumberSeries::TYPE_SALES_INVOICE,
    )))->toThrow(DocumentNumberUnavailableException::class, 'authorised range');

    expect($series->fresh()->next_number)->toBe(11);
});

it('names the range and the document type when refusing', function () {
    DocumentNumberSeries::factory()->nearlyExhausted()->create();
    DB::transaction(fn () => allocator()->allocate(DocumentNumberSeries::TYPE_SALES_INVOICE));

    // The operator needs to know a new ATP is required, not just that
    // something failed.
    expect(fn () => DB::transaction(fn () => allocator()->allocate(
        DocumentNumberSeries::TYPE_SALES_INVOICE,
    )))->toThrow(DocumentNumberUnavailableException::class, 'Authority To Print');
});

it('treats a series with no range as unbounded', function () {
    // Every series starts unregistered, before the client supplies permit
    // details. That must not block issuing.
    $series = DocumentNumberSeries::factory()->create(['next_number' => 999_999]);

    expect($series->hasAuthorityToPrint())->toBeFalse()
        ->and($series->remainingInRange())->toBeNull();

    $number = DB::transaction(fn (): string => allocator()->allocate(
        DocumentNumberSeries::TYPE_SALES_INVOICE,
    ));

    expect($number)->toBe('SI-999999');
});

/* ── Missing and inactive series ────────────────────────────────────── */

it('refuses when the school has no series for the document type', function () {
    expect(fn () => DB::transaction(fn () => allocator()->allocate(
        DocumentNumberSeries::TYPE_OFFICIAL_RECEIPT,
    )))->toThrow(DocumentNumberUnavailableException::class, 'no numbering series');
});

it('refuses an inactive series', function () {
    DocumentNumberSeries::factory()->inactive()->create();

    expect(fn () => DB::transaction(fn () => allocator()->allocate(
        DocumentNumberSeries::TYPE_SALES_INVOICE,
    )))->toThrow(DocumentNumberUnavailableException::class, 'inactive');
});

it('keeps separate document types on separate counters', function () {
    DocumentNumberSeries::factory()->create(['next_number' => 5]);
    DocumentNumberSeries::factory()
        ->ofType(DocumentNumberSeries::TYPE_BILL, 'BILL-')
        ->create(['next_number' => 77]);

    $invoice = DB::transaction(fn (): string => allocator()->allocate(
        DocumentNumberSeries::TYPE_SALES_INVOICE,
    ));
    $bill = DB::transaction(fn (): string => allocator()->allocate(
        DocumentNumberSeries::TYPE_BILL,
    ));

    expect($invoice)->toBe('SI-000005')
        ->and($bill)->toBe('BILL-000077');
});

/* ── peek() ─────────────────────────────────────────────────────────── */

it('previews the next number without taking it', function () {
    DocumentNumberSeries::factory()->create(['next_number' => 7]);

    expect(allocator()->peek(DocumentNumberSeries::TYPE_SALES_INVOICE))->toBe('SI-000007')
        // Crucially, the counter has not moved.
        ->and(DocumentNumberSeries::query()->first()->next_number)->toBe(7);
});

it('previews nothing when no number could be issued', function () {
    expect(allocator()->peek(DocumentNumberSeries::TYPE_SALES_INVOICE))->toBeNull();

    DocumentNumberSeries::factory()->nearlyExhausted()->create(['next_number' => 11]);

    expect(allocator()->peek(DocumentNumberSeries::TYPE_SALES_INVOICE))->toBeNull();
});
