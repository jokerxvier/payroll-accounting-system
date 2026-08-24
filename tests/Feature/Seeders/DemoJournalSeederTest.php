<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\TaxRate;
use App\Models\User;
use Database\Seeders\AccountingCatalogSeeder;
use Database\Seeders\DemoJournalSeeder;
use Spatie\Multitenancy\Models\Tenant;

/*
 * DemoJournalSeeder — demo books for the default school.
 *
 * The point of testing a demo seeder is that it posts through the real
 * actions rather than inserting rows. If it ever stopped doing that, it
 * could seed a ledger the application itself would have refused to create,
 * and everything downstream would disagree for reasons that look like bugs
 * elsewhere. These tests pin that the seeded books are genuinely valid.
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    // posted_by_user_id is a foreign key, so an actor has to exist.
    User::factory()->create()->syncRoles(['accountant']);
});

it('seeds a set of posted entries', function () {
    $this->seed(DemoJournalSeeder::class);

    expect(JournalEntry::query()->posted()->count())->toBeGreaterThan(0)
        ->and(JournalEntry::query()->where('reference', 'like', 'DEMO-%')->count())
        ->toBeGreaterThan(0);
});

it('seeds only balanced entries', function () {
    $this->seed(DemoJournalSeeder::class);

    // Every posted entry went through PostJournalEntry, so this should hold
    // by construction — asserting it catches the case where someone
    // "optimises" the seeder into raw inserts.
    foreach (JournalEntry::query()->posted()->get() as $entry) {
        expect($entry->isBalanced())->toBeTrue(
            "Entry {$entry->entry_number} does not balance",
        );

        $debits = $entry->lines()->sum('debit_centavos');
        $credits = $entry->lines()->sum('credit_centavos');

        expect($debits)->toBe($credits)
            ->and($debits)->toBeGreaterThan(0);
    }
});

it('gives every posted entry a number and a period', function () {
    $this->seed(DemoJournalSeeder::class);

    foreach (JournalEntry::query()->posted()->get() as $entry) {
        expect($entry->entry_number)->not()->toBeNull()
            ->and($entry->accounting_period_id)->not()->toBeNull()
            ->and($entry->posted_by_user_id)->not()->toBeNull();
    }
});

it('seeds a reversed pair that offsets', function () {
    $this->seed(DemoJournalSeeder::class);

    $reversal = JournalEntry::query()->whereNotNull('reversal_of_entry_id')->first();

    expect($reversal)->not()->toBeNull();

    $original = $reversal->reversalOf;

    // Both stay posted and cancel out — the worked example of a correction.
    expect($original->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($original->hasBeenReversed())->toBeTrue()
        ->and($reversal->total_debit_centavos)->toBe($original->total_debit_centavos);

    $account = $original->lines->first()->account_id;

    $net = JournalEntryLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('status', JournalEntry::STATUS_POSTED))
        ->whereIn('journal_entry_id', [$original->id, $reversal->id])
        ->where('account_id', $account)
        ->get()
        ->sum(fn (JournalEntryLine $l) => $l->debit_centavos - $l->credit_centavos);

    expect($net)->toBe(0);
});

it('leaves one draft behind', function () {
    $this->seed(DemoJournalSeeder::class);

    $drafts = JournalEntry::query()->where('status', JournalEntry::STATUS_DRAFT)->get();

    expect($drafts)->toHaveCount(1)
        // A draft has not reached the ledger, so it burns no number.
        ->and($drafts->first()->entry_number)->toBeNull();
});

it('opens a period for the month it seeds into', function () {
    expect(AccountingPeriod::query()->count())->toBe(0);

    $this->seed(DemoJournalSeeder::class);

    expect(AccountingPeriod::query()->count())->toBe(1)
        ->and(AccountingPeriod::query()->first()->isOpen())->toBeTrue();
});

it('is idempotent', function () {
    $this->seed(DemoJournalSeeder::class);

    $entries = JournalEntry::query()->count();
    $lines = JournalEntryLine::query()->count();

    // Re-posting would duplicate the books rather than refresh them.
    $this->seed(DemoJournalSeeder::class);
    $this->seed(DemoJournalSeeder::class);

    expect(JournalEntry::query()->count())->toBe($entries)
        ->and(JournalEntryLine::query()->count())->toBe($lines);
});

it('skips when there is no chart of accounts to post against', function () {
    // Tax rates FK to the chart, so they have to go first.
    TaxRate::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(DemoJournalSeeder::class);

    expect(JournalEntry::query()->count())->toBe(0);
});

it('restores the tenant it was called with', function () {
    $before = Tenant::current()?->getKey();

    $this->seed(DemoJournalSeeder::class);

    // The seeder binds the default school to make BelongsToTenant work; it
    // must not leave the process pointing somewhere unexpected afterwards.
    expect(Tenant::current()?->getKey())->toBe($before);
});

it('leaves the books provably self-consistent', function () {
    $this->seed(DemoJournalSeeder::class);

    // The trial-balance identity across everything seeded: total debits
    // equal total credits over all posted lines.
    $lines = JournalEntryLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('status', JournalEntry::STATUS_POSTED))
        ->get();

    expect($lines->sum('debit_centavos'))->toBe($lines->sum('credit_centavos'))
        ->and($lines->sum('debit_centavos'))->toBeGreaterThan(0);
});
