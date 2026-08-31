<?php

declare(strict_types=1);

use App\Models\Pas\Invoice;
use App\Models\Pas\School;
use App\Services\Accounting\InvoiceNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Invoice numbering after the 2026-08-30 removal of the BIR series.
 *
 * What is pinned:
 *   - sales and bills run on separate counters, with distinct prefixes
 *   - the year comes from the ISSUE date, not from today
 *   - numbers are per-school, and one school never sees another's counter
 *   - gaps are tolerated; uniqueness is not optional
 */

beforeEach(function (): void {
    Invoice::query()->withoutGlobalScopes()->delete();
});

function invoiceNumbers(): InvoiceNumberAllocator
{
    return app(InvoiceNumberAllocator::class);
}

function numberedInvoice(string $type, string $number, string $issueDate): Invoice
{
    return Invoice::factory()->create([
        'type' => $type,
        'number' => $number,
        'issue_date' => CarbonImmutable::parse($issueDate),
    ]);
}

it('starts each year at one', function (): void {
    $number = DB::transaction(fn (): string => invoiceNumbers()->allocate(
        Invoice::TYPE_SALES,
        CarbonImmutable::parse('2026-08-30'),
    ));

    expect($number)->toBe('INV-2026-00001');
});

it('continues from the highest number already issued', function (): void {
    numberedInvoice(Invoice::TYPE_SALES, 'INV-2026-00007', '2026-08-01');

    $number = DB::transaction(fn (): string => invoiceNumbers()->allocate(
        Invoice::TYPE_SALES,
        CarbonImmutable::parse('2026-08-30'),
    ));

    expect($number)->toBe('INV-2026-00008');
});

it('keeps bills on their own counter with their own prefix', function (): void {
    numberedInvoice(Invoice::TYPE_SALES, 'INV-2026-00042', '2026-08-01');

    $bill = DB::transaction(fn (): string => invoiceNumbers()->allocate(
        Invoice::TYPE_PURCHASE,
        CarbonImmutable::parse('2026-08-30'),
    ));

    // A supplier's document does not advance our sales counter.
    expect($bill)->toBe('BILL-2026-00001');
});

it('numbers by the issue date year, not by today', function (): void {
    numberedInvoice(Invoice::TYPE_SALES, 'INV-2025-00003', '2025-06-01');

    $backdated = DB::transaction(fn (): string => invoiceNumbers()->allocate(
        Invoice::TYPE_SALES,
        CarbonImmutable::parse('2025-12-31'),
    ));

    // A document backdated into last year draws from last year's block, so
    // its number stays consistent with the period it belongs to.
    expect($backdated)->toBe('INV-2025-00004');
});

it('starts a fresh block when the year rolls over', function (): void {
    numberedInvoice(Invoice::TYPE_SALES, 'INV-2026-00099', '2026-12-31');

    $number = DB::transaction(fn (): string => invoiceNumbers()->allocate(
        Invoice::TYPE_SALES,
        CarbonImmutable::parse('2027-01-01'),
    ));

    expect($number)->toBe('INV-2027-00001');
});

it('never reads another school\'s counter', function (): void {
    $other = School::factory()->create();
    Invoice::factory()->create([
        'school_id' => $other->getKey(),
        'type' => Invoice::TYPE_SALES,
        'number' => 'INV-2026-00500',
        'issue_date' => CarbonImmutable::parse('2026-08-01'),
    ]);

    $number = DB::transaction(fn (): string => invoiceNumbers()->allocate(
        Invoice::TYPE_SALES,
        CarbonImmutable::parse('2026-08-30'),
    ));

    expect($number)->toBe('INV-2026-00001');
});
