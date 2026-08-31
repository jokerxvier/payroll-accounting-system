<?php

declare(strict_types=1);

use App\Actions\Accounting\PostOpeningBalances;
use App\Actions\Accounting\ReverseJournalEntry;
use App\Exceptions\ClosedAccountingPeriodException;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\School;
use App\Models\User;
use App\Services\Accounting\Reports\LedgerReportService;
use Carbon\CarbonImmutable;

/*
 * Phase 5 Slice 9 — the cutover snapshot.
 *
 * The properties pinned here:
 *
 *   - a balanced snapshot posts as an ordinary entry, through the one door
 *   - income and expense accounts are refused, because they close out at
 *     year end and an opening balance on one overstates the next Income
 *     Statement for good
 *   - a second standing snapshot is refused; correcting means reversing
 *   - the difference is plugged to Retained Earnings only when asked for
 *   - and the one that justifies the whole "reports need no arithmetic
 *     change" claim: a snapshot dated before a range lands in that range's
 *     OPENING columns, not in its movement, and the trial balance still foots
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->cash = ChartOfAccount::factory()->asset()->create(['code' => '1100', 'name' => 'Cash on Hand']);
    $this->receivable = ChartOfAccount::factory()->asset()->create(['code' => '1200', 'name' => 'Accounts Receivable']);
    $this->payable = ChartOfAccount::factory()->liability()->create(['code' => '2100', 'name' => 'Accounts Payable']);
    $this->retained = ChartOfAccount::factory()->equity()
        ->system(ChartOfAccount::SYSTEM_RETAINED_EARNINGS)
        ->create(['code' => '3200', 'name' => 'Retained Earnings']);
    $this->income = ChartOfAccount::factory()->income()->create(['code' => '4100', 'name' => 'Tuition Fee Income']);

    AccountingPeriod::factory()->forMonth(CarbonImmutable::parse('2026-06-01'))->create();
    AccountingPeriod::factory()->forMonth(CarbonImmutable::parse('2026-07-01'))->create();

    $this->actor = User::factory()->create();
});

function postOpeningBalances(): PostOpeningBalances
{
    return app(PostOpeningBalances::class);
}

/**
 * Assets 1,000,000 = liabilities 400,000 + equity 600,000, in centavos.
 *
 * @return list<array{account_id: int, debit_centavos: int, credit_centavos: int}>
 */
function balancedSnapshot(): array
{
    return [
        ['account_id' => test()->cash->getKey(), 'debit_centavos' => 700_000_00, 'credit_centavos' => 0],
        ['account_id' => test()->receivable->getKey(), 'debit_centavos' => 300_000_00, 'credit_centavos' => 0],
        ['account_id' => test()->payable->getKey(), 'debit_centavos' => 0, 'credit_centavos' => 400_000_00],
        ['account_id' => test()->retained->getKey(), 'debit_centavos' => 0, 'credit_centavos' => 600_000_00],
    ];
}

/* ── Posting ────────────────────────────────────────────────────────── */

it('posts a balanced snapshot through the ordinary posting path', function (): void {
    $entry = postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    );

    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->source_type)->toBe(JournalEntry::SOURCE_OPENING_BALANCE)
        ->and($entry->source_id)->toBeNull()
        ->and($entry->entry_number)->not->toBeNull()
        ->and($entry->accounting_period_id)->not->toBeNull()
        ->and($entry->total_debit_centavos)->toBe(1_000_000_00)
        ->and($entry->total_credit_centavos)->toBe(1_000_000_00)
        ->and($entry->lines()->count())->toBe(4);
});

it('orders the lines by account code rather than by spreadsheet row', function (): void {
    $reversed = array_reverse(balancedSnapshot());

    $entry = postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        $reversed,
        (int) $this->actor->getKey(),
    );

    $codes = $entry->lines()->orderBy('line_number')->get()
        ->map(fn (JournalEntryLine $l): string => ChartOfAccount::find($l->account_id)->code)
        ->all();

    expect($codes)->toBe(['1100', '1200', '2100', '3200']);
});

it('stamps the cutover date on the school', function (): void {
    $entry = postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    );

    $school = School::query()->findOrFail($entry->school_id);

    expect($school->books_opened_on?->toDateString())->toBe('2026-06-30');
});

/* ── The refusals ───────────────────────────────────────────────────── */

it('refuses an unbalanced snapshot when no plug was asked for', function (): void {
    $lines = balancedSnapshot();
    $lines[0]['debit_centavos'] = 650_000_00; // 50,000 short

    postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        $lines,
        (int) $this->actor->getKey(),
    );
})->throws(UnbalancedJournalEntryException::class);

it('refuses an income account, naming retained earnings as the right home', function (): void {
    $lines = balancedSnapshot();
    $lines[] = [
        'account_id' => $this->income->getKey(),
        'debit_centavos' => 0,
        'credit_centavos' => 50_000_00,
    ];

    expect(fn () => postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        $lines,
        (int) $this->actor->getKey(),
    ))->toThrow(DomainException::class, 'Retained Earnings');
});

it('refuses a second snapshot while the first still stands', function (): void {
    postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    );

    expect(fn () => postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    ))->toThrow(DomainException::class, 'already posted');
});

it('allows a fresh snapshot once the standing one is reversed', function (): void {
    $first = postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    );

    app(ReverseJournalEntry::class)->execute($first, (int) $this->actor->getKey());

    // Both halves stay posted and carry the sentinel, so the guard has to
    // discount the reversed original AND the reversal itself.
    expect(JournalEntry::query()->openingBalance()->posted()->count())->toBe(2);

    $second = postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    );

    expect($second->status)->toBe(JournalEntry::STATUS_POSTED);
});

it('clears the cutover date when the snapshot is reversed', function (): void {
    $entry = postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    );

    expect(School::query()->whereNotNull('books_opened_on')->count())->toBe(1);

    app(ReverseJournalEntry::class)->execute($entry, (int) $this->actor->getKey());

    expect(School::query()->whereNotNull('books_opened_on')->count())->toBe(0);
});

it('refuses a cutover date no period covers', function (): void {
    postOpeningBalances()->execute(
        CarbonImmutable::parse('2020-01-31'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    );
})->throws(ClosedAccountingPeriodException::class);

it('refuses a cutover date whose period is closed', function (): void {
    AccountingPeriod::query()->withoutGlobalScopes()
        ->update(['status' => AccountingPeriod::STATUS_CLOSED]);

    postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    );
})->throws(ClosedAccountingPeriodException::class);

/* ── The plug ───────────────────────────────────────────────────────── */

it('routes a shortfall to retained earnings only when asked', function (): void {
    // Assets 1,000,000 against a liability of 400,000 and nothing in equity:
    // 600,000 of accumulated result the client did not state.
    $lines = [
        ['account_id' => $this->cash->getKey(), 'debit_centavos' => 700_000_00, 'credit_centavos' => 0],
        ['account_id' => $this->receivable->getKey(), 'debit_centavos' => 300_000_00, 'credit_centavos' => 0],
        ['account_id' => $this->payable->getKey(), 'debit_centavos' => 0, 'credit_centavos' => 400_000_00],
    ];

    $entry = postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        $lines,
        (int) $this->actor->getKey(),
        plugToRetainedEarnings: true,
    );

    $plug = $entry->lines()->where('account_id', $this->retained->getKey())->sole();

    expect($plug->credit_centavos)->toBe(600_000_00)
        ->and($plug->debit_centavos)->toBe(0)
        ->and($entry->total_debit_centavos)->toBe($entry->total_credit_centavos);
});

it('plugs the other way when credits exceed debits', function (): void {
    $lines = [
        ['account_id' => $this->cash->getKey(), 'debit_centavos' => 100_000_00, 'credit_centavos' => 0],
        ['account_id' => $this->payable->getKey(), 'debit_centavos' => 0, 'credit_centavos' => 400_000_00],
    ];

    $entry = postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        $lines,
        (int) $this->actor->getKey(),
        plugToRetainedEarnings: true,
    );

    $plug = $entry->lines()->where('account_id', $this->retained->getKey())->sole();

    // Liabilities exceeding assets is a deficit, and a deficit is a DEBIT
    // to retained earnings.
    expect($plug->debit_centavos)->toBe(300_000_00)
        ->and($plug->credit_centavos)->toBe(0);
});

it('adds no plug line when the sheet already balances', function (): void {
    $entry = postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
        plugToRetainedEarnings: true,
    );

    expect($entry->lines()->count())->toBe(4);
});

/* ── The claim that reports needed no arithmetic change ─────────────── */

it('lands in the opening columns of a later range, not in its movement', function (): void {
    postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    );

    $trialBalance = app(LedgerReportService::class)->trialBalance(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
    );

    $cashRow = collect($trialBalance->rows)->firstWhere('code', '1100');

    expect($cashRow->openingDebitCentavos)->toBe(700_000_00)
        ->and($cashRow->periodDebitCentavos)->toBe(0)
        ->and($cashRow->periodCreditCentavos)->toBe(0)
        // All three column pairs, so an opening/period discrepancy cannot
        // net out at closing and hide.
        ->and($trialBalance->isBalanced())->toBeTrue();
});

it('carries the snapshot into the general ledger as brought forward', function (): void {
    postOpeningBalances()->execute(
        CarbonImmutable::parse('2026-06-30'),
        balancedSnapshot(),
        (int) $this->actor->getKey(),
    );

    $ledger = app(LedgerReportService::class)->accountLedger(
        $this->cash,
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
    );

    expect($ledger->openingRawCentavos)->toBe(700_000_00)
        ->and($ledger->lines)->toBeEmpty();
});
