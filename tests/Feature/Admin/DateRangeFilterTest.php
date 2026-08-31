<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * Date-range filtering on the invoice, journal and payment lists.
 *
 * The assertion that matters in every one of these is the UPPER bound. A
 * `date` column compared against a bare 'Y-m-d' silently drops the last day
 * under SQLite — Eloquent's date cast writes 'Y-m-d H:i:s' and the comparison
 * becomes a string compare, so '2026-08-31 00:00:00' <= '2026-08-31' is false.
 *
 * That bug was found once in Slice 8a and fixed inside LedgerReportService.
 * These three filters are the next places it could reappear, which is why
 * DayBoundary now holds the rule and why each list asserts its own last day.
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    Invoice::query()->withoutGlobalScopes()->delete();
    Payment::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    $this->customer = Contact::factory()->customer()->create();
    $this->cash = ChartOfAccount::query()->where('code', '1110')->firstOrFail();
});

function rangeAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

/* ── Invoices ───────────────────────────────────────────────────────── */

it('filters invoices by issue date, inclusive at both ends', function (): void {
    foreach (['2026-07-31', '2026-08-01', '2026-08-31', '2026-09-01'] as $i => $date) {
        Invoice::factory()->create([
            'contact_id' => $this->customer->getKey(),
            'type' => Invoice::TYPE_SALES,
            'number' => 'INV-'.$i,
            'issue_date' => CarbonImmutable::parse($date),
        ]);
    }

    $this->actingAs(rangeAuthAs('accountant'))
        ->get(route('admin.invoices.index', ['from' => '2026-08-01', 'to' => '2026-08-31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Both boundary days present, neither neighbour.
            ->where('invoices.total', 2)
            ->where('filters.from', '2026-08-01')
            ->where('filters.to', '2026-08-31'));
});

it('accepts an open-ended invoice range', function (): void {
    Invoice::factory()->create([
        'contact_id' => $this->customer->getKey(),
        'issue_date' => CarbonImmutable::parse('2026-07-01'),
        'number' => 'INV-A',
    ]);
    Invoice::factory()->create([
        'contact_id' => $this->customer->getKey(),
        'issue_date' => CarbonImmutable::parse('2026-09-01'),
        'number' => 'INV-B',
    ]);

    // "Everything since August" is as ordinary a question as a closed range.
    $this->actingAs(rangeAuthAs('accountant'))
        ->get(route('admin.invoices.index', ['from' => '2026-08-01']))
        ->assertInertia(fn ($page) => $page->where('invoices.total', 1));
});

it('keeps the status filter when a date range is applied', function (): void {
    Invoice::factory()->create([
        'contact_id' => $this->customer->getKey(),
        'issue_date' => CarbonImmutable::parse('2026-08-10'),
        'status' => Invoice::STATUS_DRAFT,
        'number' => 'INV-D',
    ]);
    Invoice::factory()->create([
        'contact_id' => $this->customer->getKey(),
        'issue_date' => CarbonImmutable::parse('2026-08-11'),
        'status' => Invoice::STATUS_APPROVED,
        'number' => 'INV-E',
    ]);

    $this->actingAs(rangeAuthAs('accountant'))
        ->get(route('admin.invoices.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'status' => 'draft',
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('invoices.total', 1)
            ->where('filters.status', 'draft'));
});

/* ── Journal entries ────────────────────────────────────────────────── */

it('filters journal entries by date, inclusive at both ends', function (): void {
    foreach (['2026-07-31', '2026-08-01', '2026-08-31', '2026-09-01'] as $i => $date) {
        JournalEntry::factory()->create([
            'entry_number' => 'JE-'.$i,
            'date' => CarbonImmutable::parse($date),
        ]);
    }

    $this->actingAs(rangeAuthAs('accountant'))
        ->get(route('admin.journal-entries.index', ['from' => '2026-08-01', 'to' => '2026-08-31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entries.total', 2)
            ->where('filters.from', '2026-08-01')
            ->where('filters.to', '2026-08-31'));
});

/* ── Payments ───────────────────────────────────────────────────────── */

it('filters payments by payment date, inclusive at both ends', function (): void {
    foreach (['2026-07-31', '2026-08-01', '2026-08-31', '2026-09-01'] as $date) {
        Payment::factory()->receipt()->create([
            'contact_id' => $this->customer->getKey(),
            'cash_account_id' => $this->cash->getKey(),
            'payment_date' => CarbonImmutable::parse($date),
        ]);
    }

    $this->actingAs(rangeAuthAs('accountant'))
        ->get(route('admin.payments.index', ['from' => '2026-08-01', 'to' => '2026-08-31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('payments.total', 2)
            ->where('filters.from', '2026-08-01')
            ->where('filters.to', '2026-08-31'));
});

/* ── Bad input ──────────────────────────────────────────────────────── */

it('ignores an unreadable date rather than erroring', function (): void {
    Invoice::factory()->create([
        'contact_id' => $this->customer->getKey(),
        'issue_date' => CarbonImmutable::parse('2026-08-10'),
        'number' => 'INV-F',
    ]);

    // Someone editing a URL should not get an error page on a list view.
    $this->actingAs(rangeAuthAs('accountant'))
        ->get(route('admin.invoices.index', ['from' => 'not-a-date']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('invoices.total', 1)
            ->where('filters.from', null));
});
