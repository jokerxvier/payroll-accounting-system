<?php

declare(strict_types=1);

use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\ReverseJournalEntry;
use App\Exceptions\ClosedAccountingPeriodException;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Phase 5 Slice 2 — the ledger's invariants.
 *
 * These are the tests the whole accounting module rests on. Everything that
 * will ever write to the books goes through PostJournalEntry, so if these
 * hold, no downstream slice can produce an unbalanced or back-dated posting.
 *
 * Pinned:
 *   - debits must equal credits, exactly, in integer centavos
 *   - a closed period refuses new entries
 *   - a posted entry is never mutated; voiding posts its mirror
 *   - a line moves exactly one side, by a positive amount
 *   - entry numbers are sequential and unique per school
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->period = AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    $this->cash = ChartOfAccount::factory()->asset()->create(['code' => '1100', 'name' => 'Cash']);
    $this->income = ChartOfAccount::factory()->income()->create(['code' => '4100', 'name' => 'Tuition']);

    $this->actor = User::factory()->create();
});

function entryOn(string $date = '2026-08-15'): JournalEntry
{
    return JournalEntry::factory()->create(['date' => CarbonImmutable::parse($date)]);
}

function addLine(JournalEntry $entry, ChartOfAccount $account, int $debit, int $credit, int $n = 1): JournalEntryLine
{
    return JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $account->getKey(),
        'debit_centavos' => $debit,
        'credit_centavos' => $credit,
        'line_number' => $n,
    ]);
}

function poster(): PostJournalEntry
{
    return app(PostJournalEntry::class);
}

/* ── The balance invariant ──────────────────────────────────────────── */

it('posts a balanced entry', function () {
    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 500_000, 2);

    $posted = poster()->execute($entry, (int) $this->actor->getKey());

    expect($posted->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($posted->total_debit_centavos)->toBe(500_000)
        ->and($posted->total_credit_centavos)->toBe(500_000)
        ->and($posted->isBalanced())->toBeTrue()
        ->and($posted->posted_by_user_id)->toBe($this->actor->getKey())
        ->and($posted->accounting_period_id)->toBe($this->period->getKey());
});

it('refuses an entry whose debits and credits differ', function () {
    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 499_999, 2);

    expect(fn () => poster()->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(UnbalancedJournalEntryException::class);

    // Nothing was written — the entry is still a draft.
    expect($entry->fresh()->status)->toBe(JournalEntry::STATUS_DRAFT)
        ->and($entry->fresh()->posted_at)->toBeNull();
});

it('refuses an entry that is off by a single centavo', function () {
    // The cheapest possible imbalance, and the one a rounding bug produces.
    $entry = entryOn();
    addLine($entry, $this->cash, 100_001, 0, 1);
    addLine($entry, $this->income, 0, 100_000, 2);

    expect(fn () => poster()->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(UnbalancedJournalEntryException::class);
});

it('names both totals and the difference when refusing', function () {
    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 400_000, 2);

    // The difference is what tells an operator what they mistyped.
    expect(fn () => poster()->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(UnbalancedJournalEntryException::class, 'off by 1000.00');
});

it('balances across many lines, not just two', function () {
    $expense = ChartOfAccount::factory()->expense()->create(['code' => '5100']);

    $entry = entryOn();
    addLine($entry, $this->cash, 0, 300_000, 1);
    addLine($entry, $expense, 125_000, 0, 2);
    addLine($entry, $expense, 75_000, 0, 3);
    addLine($entry, $expense, 100_000, 0, 4);

    $posted = poster()->execute($entry, (int) $this->actor->getKey());

    expect($posted->total_debit_centavos)->toBe(300_000)
        ->and($posted->total_credit_centavos)->toBe(300_000);
});

/* ── Line shape ─────────────────────────────────────────────────────── */

it('refuses an entry with fewer than two lines', function () {
    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);

    expect(fn () => poster()->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(DomainException::class, 'at least two lines');
});

it('refuses a line that moves both sides at once', function () {
    // Such a line still lets the entry "balance" while describing nothing.
    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 500_000, 1);
    addLine($entry, $this->income, 0, 500_000, 2);

    expect(fn () => poster()->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(DomainException::class, 'both a debit and a credit');
});

it('refuses a negative amount rather than treating it as the other side', function () {
    $entry = entryOn();
    addLine($entry, $this->cash, -500_000, 0, 1);
    addLine($entry, $this->income, 0, -500_000, 2);

    expect(fn () => poster()->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(DomainException::class, 'negative amount');
});

it('refuses an entry that balances at zero', function () {
    $entry = entryOn();
    addLine($entry, $this->cash, 0, 0, 1);
    addLine($entry, $this->income, 0, 0, 2);

    expect(fn () => poster()->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(DomainException::class, 'non-zero amount');
});

/* ── Period locking ─────────────────────────────────────────────────── */

it('refuses to post into a closed period', function () {
    $this->period->forceFill([
        'status' => AccountingPeriod::STATUS_CLOSED,
        'closed_at' => now(),
    ])->save();

    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 500_000, 2);

    expect(fn () => poster()->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(ClosedAccountingPeriodException::class);

    expect($entry->fresh()->status)->toBe(JournalEntry::STATUS_DRAFT);
});

it('refuses to post on a date no period covers', function () {
    $entry = entryOn('2027-03-04');
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 500_000, 2);

    expect(fn () => poster()->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(ClosedAccountingPeriodException::class, 'No accounting period covers 2027-03-04');
});

it('posts again once a closed period is reopened', function () {
    $this->period->forceFill(['status' => AccountingPeriod::STATUS_CLOSED])->save();

    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 500_000, 2);

    expect(fn () => poster()->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(ClosedAccountingPeriodException::class);

    $this->period->forceFill(['status' => AccountingPeriod::STATUS_OPEN])->save();

    expect(poster()->execute($entry->fresh(), (int) $this->actor->getKey())->status)
        ->toBe(JournalEntry::STATUS_POSTED);
});

/* ── Status machine ─────────────────────────────────────────────────── */

it('refuses to post an entry twice', function () {
    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 500_000, 2);

    $posted = poster()->execute($entry, (int) $this->actor->getKey());

    expect(fn () => poster()->execute($posted, (int) $this->actor->getKey()))
        ->toThrow(DomainException::class, 'Expected draft or pending');
});

/* ── Entry numbering ────────────────────────────────────────────────── */

it('allocates sequential entry numbers within a year', function () {
    $numbers = [];

    for ($i = 0; $i < 3; $i++) {
        $entry = entryOn();
        addLine($entry, $this->cash, 100_000, 0, 1);
        addLine($entry, $this->income, 0, 100_000, 2);
        $numbers[] = poster()->execute($entry, (int) $this->actor->getKey())->entry_number;
    }

    expect($numbers)->toBe(['JE-2026-00001', 'JE-2026-00002', 'JE-2026-00003']);
});

it('does not allocate a number until the entry actually posts', function () {
    // A draft that never posts must not burn a number.
    $draft = entryOn();
    addLine($draft, $this->cash, 100_000, 0, 1);
    addLine($draft, $this->income, 0, 100_000, 2);

    expect($draft->entry_number)->toBeNull();

    $posted = poster()->execute($draft, (int) $this->actor->getKey());

    expect($posted->entry_number)->toBe('JE-2026-00001');
});

/* ── Correction by reversal ─────────────────────────────────────────── */

it('reverses by posting a mirror image and never mutates the original', function () {
    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 500_000, 2);

    $posted = poster()->execute($entry, (int) $this->actor->getKey());
    $originalLines = $posted->lines->map(fn ($l) => [
        $l->account_id, $l->debit_centavos, $l->credit_centavos,
    ])->all();

    $reverser = app(ReverseJournalEntry::class);
    $reversal = $reverser->execute($posted, (int) $this->actor->getKey());

    // The reversal is a real posted entry with its own number.
    expect($reversal->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($reversal->entry_number)->not()->toBe($posted->entry_number)
        ->and($reversal->reversal_of_entry_id)->toBe($posted->getKey())
        ->and($reversal->isBalanced())->toBeTrue();

    // Every line swapped sides.
    $reversedLines = $reversal->lines->map(fn ($l) => [
        $l->account_id, $l->debit_centavos, $l->credit_centavos,
    ])->all();

    foreach ($originalLines as $i => [$accountId, $debit, $credit]) {
        expect($reversedLines[$i])->toBe([$accountId, $credit, $debit]);
    }

    // The original keeps its status, figures, and posting stamps. Only the
    // reversal stamps are added, and they change no figure.
    $original = $posted->fresh();
    expect($original->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($original->total_debit_centavos)->toBe(500_000)
        ->and($original->total_credit_centavos)->toBe(500_000)
        ->and($original->posted_at)->not()->toBeNull()
        ->and($original->reversed_by_user_id)->toBe($this->actor->getKey())
        ->and($original->hasBeenReversed())->toBeTrue()
        ->and($original->lines()->count())->toBe(2);
});

it('keeps the reversed original in scope for reports', function () {
    // The pair has to offset. If the original dropped out of scopePosted(),
    // the reversal would stand alone and the account would be wrong by the
    // full amount in the opposite direction.
    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 500_000, 2);

    $posted = poster()->execute($entry, (int) $this->actor->getKey());
    app(ReverseJournalEntry::class)->execute($posted, (int) $this->actor->getKey());

    expect(JournalEntry::query()->posted()->count())->toBe(2);
});

it('nets the ledger to zero after a reversal', function () {
    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 500_000, 2);

    $posted = poster()->execute($entry, (int) $this->actor->getKey());
    app(ReverseJournalEntry::class)->execute($posted, (int) $this->actor->getKey());

    // Across every posted entry, cash nets to nothing — which is the whole
    // point of correcting by reversal rather than by deletion.
    $net = JournalEntryLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('status', JournalEntry::STATUS_POSTED))
        ->where('account_id', $this->cash->getKey())
        ->get()
        ->sum(fn (JournalEntryLine $l) => $l->debit_centavos - $l->credit_centavos);

    expect($net)->toBe(0);
});

it('refuses to reverse the same entry twice', function () {
    $entry = entryOn();
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 500_000, 2);

    $posted = poster()->execute($entry, (int) $this->actor->getKey());
    $reverser = app(ReverseJournalEntry::class);
    $reverser->execute($posted, (int) $this->actor->getKey());

    // A second reversal would overshoot, leaving the account wrong by the
    // full amount in the other direction.
    expect(fn () => $reverser->execute($posted->fresh(), (int) $this->actor->getKey()))
        ->toThrow(DomainException::class, 'already reversed');
});

it('refuses to reverse a draft', function () {
    $entry = entryOn();

    expect(fn () => app(ReverseJournalEntry::class)->execute($entry, (int) $this->actor->getKey()))
        ->toThrow(DomainException::class);
});

it('posts the reversal into an open period when the original period has closed', function () {
    $entry = entryOn('2026-08-15');
    addLine($entry, $this->cash, 500_000, 0, 1);
    addLine($entry, $this->income, 0, 500_000, 2);
    $posted = poster()->execute($entry, (int) $this->actor->getKey());

    // Books closed for August; September is open.
    $this->period->forceFill(['status' => AccountingPeriod::STATUS_CLOSED])->save();
    $september = AccountingPeriod::factory()->create([
        'code' => '2026-09',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
    ]);

    $reverser = app(ReverseJournalEntry::class);

    // Defaulting to the original's date lands in the closed period, so it
    // is refused rather than silently re-dated.
    expect(fn () => $reverser->execute($posted, (int) $this->actor->getKey()))
        ->toThrow(ClosedAccountingPeriodException::class);
    expect($posted->fresh()->hasBeenReversed())->toBeFalse();

    // Naming an open date is how the correction gets made.
    $reversal = $reverser->execute(
        $posted->fresh(),
        (int) $this->actor->getKey(),
        CarbonImmutable::parse('2026-09-05'),
    );

    expect($reversal->accounting_period_id)->toBe($september->getKey())
        ->and($posted->fresh()->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($posted->fresh()->hasBeenReversed())->toBeTrue();
});
