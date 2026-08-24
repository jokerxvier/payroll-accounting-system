<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\School;
use App\Services\Accounting\Reports\LedgerReportService;
use App\Services\Accounting\Reports\TrialBalanceRow;
use Carbon\CarbonImmutable;

/*
 * Phase 5 Slice 8a — the read side of the ledger.
 *
 * Slices 1–7 each proved their own posting was balanced. This is the first
 * code that adds all of them up, so the properties pinned here are the ones
 * that make every later financial statement trustworthy:
 *
 *   - the two columns of a trial balance foot to the same figure, always
 *   - only posted entries count, and a reversal nets its original to zero
 *   - the range is taken on the entry's own date, never on when it was posted
 *   - a credit-normal account reports a positive balance when it is in credit
 *   - one school's ledger never contains another school's figures
 *
 * The last one is worth stating plainly: every report in this module reads
 * across the whole ledger at once, which makes it the widest tenancy surface
 * in the codebase.
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->cash = ChartOfAccount::factory()->asset()->create(['code' => '1100', 'name' => 'Cash on Hand']);
    $this->receivable = ChartOfAccount::factory()->asset()->create(['code' => '1200', 'name' => 'Accounts Receivable']);
    $this->payable = ChartOfAccount::factory()->liability()->create(['code' => '2100', 'name' => 'Accounts Payable']);
    $this->income = ChartOfAccount::factory()->income()->create(['code' => '4100', 'name' => 'Tuition Fee Income']);
    $this->expense = ChartOfAccount::factory()->expense()->create(['code' => '5100', 'name' => 'Salaries and Wages']);
});

function ledgerReports(): LedgerReportService
{
    return app(LedgerReportService::class);
}

/**
 * A posted two-line entry. Every fixture in this file is built from these so
 * the ledger under test can only ever contain balanced entries — otherwise
 * the trial-balance assertions would be proving the fixture, not the report.
 *
 * @param  array<string, mixed>  $attributes
 */
function ledgerEntry(
    string $date,
    ChartOfAccount $debit,
    ChartOfAccount $credit,
    int $centavos,
    array $attributes = [],
): JournalEntry {
    $entry = JournalEntry::factory()->create([
        'date' => CarbonImmutable::parse($date),
        'status' => JournalEntry::STATUS_POSTED,
        'posted_at' => CarbonImmutable::parse($date)->addDays(3),
        'total_debit_centavos' => $centavos,
        'total_credit_centavos' => $centavos,
        ...$attributes,
    ]);

    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $debit->getKey(),
        'debit_centavos' => $centavos,
        'credit_centavos' => 0,
        'line_number' => 1,
    ]);

    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $credit->getKey(),
        'debit_centavos' => 0,
        'credit_centavos' => $centavos,
        'line_number' => 2,
    ]);

    return $entry;
}

/** @param list<TrialBalanceRow> $rows */
function rowForCode(array $rows, string $code): TrialBalanceRow
{
    foreach ($rows as $row) {
        if ($row->code === $code) {
            return $row;
        }
    }

    throw new RuntimeException("No trial-balance row for account {$code}.");
}

/* ── The invariant the whole report exists to prove ─────────────────── */

it('foots to the same figure in both columns', function () {
    ledgerEntry('2026-07-10', $this->receivable, $this->income, 1_120_000);
    ledgerEntry('2026-08-05', $this->cash, $this->receivable, 500_000);
    ledgerEntry('2026-08-20', $this->expense, $this->payable, 333_333);

    $tb = ledgerReports()->trialBalance(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($tb->isBalanced())->toBeTrue()
        ->and($tb->closingVarianceCentavos())->toBe(0)
        ->and($tb->totalPeriodDebitCentavos())->toBe(833_333)
        ->and($tb->totalPeriodCreditCentavos())->toBe(833_333)
        ->and($tb->totalOpeningDebitCentavos())->toBe(1_120_000)
        ->and($tb->totalOpeningCreditCentavos())->toBe(1_120_000);
});

it('splits opening from period at the from date', function () {
    // Before the range.
    ledgerEntry('2026-07-31', $this->cash, $this->income, 100_000);
    // On the from date — inside the range, not the opening balance.
    ledgerEntry('2026-08-01', $this->cash, $this->income, 200_000);
    // On the to date — still inside.
    ledgerEntry('2026-08-31', $this->cash, $this->income, 400_000);
    // After the range — invisible entirely.
    ledgerEntry('2026-09-01', $this->cash, $this->income, 800_000);

    $tb = ledgerReports()->trialBalance(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    $cash = rowForCode($tb->rows, '1100');

    expect($cash->openingRawCentavos())->toBe(100_000)
        ->and($cash->periodDebitCentavos)->toBe(600_000)
        ->and($cash->periodCreditCentavos)->toBe(0)
        ->and($cash->closingRawCentavos())->toBe(700_000);
});

it('treats a null from date as since inception', function () {
    ledgerEntry('2026-07-31', $this->cash, $this->income, 100_000);
    ledgerEntry('2026-08-15', $this->cash, $this->income, 200_000);

    $tb = ledgerReports()->trialBalance(null, CarbonImmutable::parse('2026-08-31'));
    $cash = rowForCode($tb->rows, '1100');

    expect($cash->openingRawCentavos())->toBe(0)
        ->and($cash->periodDebitCentavos)->toBe(300_000)
        ->and($cash->closingRawCentavos())->toBe(300_000);
});

/* ── Which entries count ────────────────────────────────────────────── */

it('ignores entries that never reached the ledger', function () {
    foreach ([JournalEntry::STATUS_DRAFT, JournalEntry::STATUS_PENDING, JournalEntry::STATUS_VOIDED] as $status) {
        ledgerEntry('2026-08-10', $this->cash, $this->income, 500_000, ['status' => $status]);
    }

    $tb = ledgerReports()->trialBalance(null, CarbonImmutable::parse('2026-08-31'));

    expect($tb->rows)->toBeEmpty()
        ->and($tb->totalClosingDebitCentavos())->toBe(0);
});

it('keeps both halves of a reversal so they cancel out', function () {
    $original = ledgerEntry('2026-08-10', $this->cash, $this->income, 500_000);
    // The reversal is posted too — per JournalEntry::scopePosted, dropping
    // the original would leave the reversal unmatched and swing the account
    // by the full amount in the wrong direction.
    ledgerEntry('2026-08-12', $this->income, $this->cash, 500_000, [
        'reversal_of_entry_id' => $original->getKey(),
    ]);

    $tb = ledgerReports()->trialBalance(null, CarbonImmutable::parse('2026-08-31'));
    $cash = rowForCode($tb->rows, '1100');

    expect($cash->closingRawCentavos())->toBe(0)
        ->and($cash->periodDebitCentavos)->toBe(500_000)
        ->and($cash->periodCreditCentavos)->toBe(500_000)
        ->and($tb->isBalanced())->toBeTrue();
});

it('ranges on the entry date, not on when it was posted', function () {
    // Dated inside August, posted in September — belongs to August.
    ledgerEntry('2026-08-28', $this->cash, $this->income, 250_000, [
        'posted_at' => CarbonImmutable::parse('2026-09-15'),
    ]);

    $august = ledgerReports()->trialBalance(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect(rowForCode($august->rows, '1100')->periodDebitCentavos)->toBe(250_000);
});

/* ── Direction ──────────────────────────────────────────────────────── */

it('reports a credit-normal account as positive when it is in credit', function () {
    ledgerEntry('2026-08-10', $this->receivable, $this->income, 750_000);

    $tb = ledgerReports()->trialBalance(null, CarbonImmutable::parse('2026-08-31'));

    $income = rowForCode($tb->rows, '4100');
    $receivable = rowForCode($tb->rows, '1200');

    // Raw is debits − credits, so income is negative there; natural states it
    // in the direction the account increases.
    expect($income->closingRawCentavos())->toBe(-750_000)
        ->and($income->closingNaturalCentavos())->toBe(750_000)
        ->and($income->closingBalanceCreditCentavos())->toBe(750_000)
        ->and($income->closingBalanceDebitCentavos())->toBe(0)
        ->and($receivable->closingNaturalCentavos())->toBe(750_000)
        ->and($receivable->closingBalanceDebitCentavos())->toBe(750_000);
});

it('prints a contra balance in the other column', function () {
    // An asset account driven into credit — an overdrawn bank account. It
    // must appear on the credit side or the columns stop footing.
    ledgerEntry('2026-08-10', $this->expense, $this->cash, 300_000);

    $tb = ledgerReports()->trialBalance(null, CarbonImmutable::parse('2026-08-31'));
    $cash = rowForCode($tb->rows, '1100');

    expect($cash->closingBalanceCreditCentavos())->toBe(300_000)
        ->and($cash->closingBalanceDebitCentavos())->toBe(0)
        ->and($cash->closingNaturalCentavos())->toBe(-300_000)
        ->and($tb->isBalanced())->toBeTrue();
});

/* ── Which accounts are printed ─────────────────────────────────────── */

it('omits accounts with no balance and no movement', function () {
    ledgerEntry('2026-08-10', $this->cash, $this->income, 100_000);

    $tb = ledgerReports()->trialBalance(null, CarbonImmutable::parse('2026-08-31'));

    expect(array_map(fn (TrialBalanceRow $row): string => $row->code, $tb->rows))
        ->toBe(['1100', '4100']);
});

it('prints the whole chart when asked', function () {
    ledgerEntry('2026-08-10', $this->cash, $this->income, 100_000);

    $tb = ledgerReports()->trialBalance(
        null,
        CarbonImmutable::parse('2026-08-31'),
        includeEmpty: true,
    );

    expect(array_map(fn (TrialBalanceRow $row): string => $row->code, $tb->rows))
        ->toBe(['1100', '1200', '2100', '4100', '5100']);
});

it('keeps a deactivated account that carries figures', function () {
    ledgerEntry('2026-08-10', $this->cash, $this->income, 100_000);
    $this->income->update(['is_active' => false]);

    $tb = ledgerReports()->trialBalance(null, CarbonImmutable::parse('2026-08-31'));

    // Dropping it because it is inactive would leave the report unbalanced,
    // which is a worse lie than showing a dead account.
    expect(rowForCode($tb->rows, '4100')->closingNaturalCentavos())->toBe(100_000)
        ->and($tb->isBalanced())->toBeTrue();
});

/* ── Tenancy ────────────────────────────────────────────────────────── */

it('never reads another school\'s ledger', function () {
    ledgerEntry('2026-08-10', $this->cash, $this->income, 100_000);

    // SchoolObserver clones the default school's chart onto a new school, so
    // the foreign ledger is posted against the other school's own copy of
    // 1100 — the realistic case, and the one where a missing tenant filter
    // would merge two schools' cash into a single line.
    $other = School::factory()->create(['slug' => 'ledger-report-foreign']);
    $foreignAccount = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $other->getKey())
        ->where('code', '1100')
        ->firstOrFail();
    $foreignEntry = JournalEntry::query()->withoutGlobalScopes()->create([
        'school_id' => $other->getKey(),
        'entry_number' => 'JE-FOREIGN-1',
        'date' => '2026-08-10',
        'status' => JournalEntry::STATUS_POSTED,
        'total_debit_centavos' => 9_999_900,
        'total_credit_centavos' => 9_999_900,
    ]);
    JournalEntryLine::query()->withoutGlobalScopes()->create([
        'school_id' => $other->getKey(),
        'journal_entry_id' => $foreignEntry->getKey(),
        'account_id' => $foreignAccount->getKey(),
        'line_number' => 1,
        'debit_centavos' => 9_999_900,
        'credit_centavos' => 0,
    ]);

    $tb = ledgerReports()->trialBalance(null, CarbonImmutable::parse('2026-08-31'));

    expect(rowForCode($tb->rows, '1100')->closingRawCentavos())->toBe(100_000)
        ->and($tb->totalClosingDebitCentavos())->toBe(100_000);
});

it('reports the ledger out of balance when it is', function () {
    // Written straight to the lines table, bypassing PostJournalEntry. This
    // is the only way the columns can disagree, and it is exactly the
    // corruption the report exists to catch — so `isBalanced()` has to be
    // capable of returning false, not merely never observed doing so.
    $entry = JournalEntry::factory()->create([
        'date' => CarbonImmutable::parse('2026-08-10'),
        'status' => JournalEntry::STATUS_POSTED,
        'total_debit_centavos' => 100_000,
        'total_credit_centavos' => 100_000,
    ]);
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $this->cash->getKey(),
        'debit_centavos' => 100_000,
        'credit_centavos' => 0,
        'line_number' => 1,
    ]);
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $this->income->getKey(),
        'debit_centavos' => 0,
        'credit_centavos' => 90_000,
        'line_number' => 2,
    ]);

    $tb = ledgerReports()->trialBalance(null, CarbonImmutable::parse('2026-08-31'));

    expect($tb->isBalanced())->toBeFalse()
        ->and($tb->closingVarianceCentavos())->toBe(10_000);
});

/* ── General ledger ─────────────────────────────────────────────────── */

it('carries an opening balance into the account ledger', function () {
    ledgerEntry('2026-07-15', $this->cash, $this->income, 400_000);
    ledgerEntry('2026-08-10', $this->cash, $this->income, 100_000);
    ledgerEntry('2026-08-20', $this->expense, $this->cash, 60_000);

    $ledger = ledgerReports()->accountLedger(
        $this->cash,
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($ledger->openingRawCentavos)->toBe(400_000)
        ->and($ledger->lines)->toHaveCount(2)
        ->and($ledger->lines[0]->runningRawCentavos)->toBe(500_000)
        ->and($ledger->lines[1]->runningRawCentavos)->toBe(440_000)
        ->and($ledger->closingRawCentavos())->toBe(440_000)
        ->and($ledger->totalDebitCentavos())->toBe(100_000)
        ->and($ledger->totalCreditCentavos())->toBe(60_000);
});

it('names the other side of each ledger line', function () {
    ledgerEntry('2026-08-10', $this->cash, $this->income, 100_000);

    $ledger = ledgerReports()->accountLedger(
        $this->cash,
        null,
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($ledger->lines[0]->contraAccounts)->toBe(['4100 Tuition Fee Income']);
});

it('orders ledger lines by date then entry', function () {
    ledgerEntry('2026-08-20', $this->cash, $this->income, 300_000);
    ledgerEntry('2026-08-05', $this->cash, $this->income, 100_000);
    ledgerEntry('2026-08-05', $this->cash, $this->income, 200_000);

    $ledger = ledgerReports()->accountLedger($this->cash, null, CarbonImmutable::parse('2026-08-31'));

    expect(array_map(fn ($line): int => $line->debitCentavos, $ledger->lines))
        ->toBe([100_000, 200_000, 300_000]);
});

it('flags a reversing line in the account ledger', function () {
    $original = ledgerEntry('2026-08-10', $this->cash, $this->income, 100_000);
    ledgerEntry('2026-08-11', $this->income, $this->cash, 100_000, [
        'reversal_of_entry_id' => $original->getKey(),
    ]);

    $ledger = ledgerReports()->accountLedger($this->cash, null, CarbonImmutable::parse('2026-08-31'));

    expect($ledger->lines[0]->isReversal)->toBeFalse()
        ->and($ledger->lines[1]->isReversal)->toBeTrue()
        ->and($ledger->closingRawCentavos())->toBe(0);
});

/* ── Journal report ─────────────────────────────────────────────────── */

it('lists posted entries in date order with their lines', function () {
    ledgerEntry('2026-08-20', $this->expense, $this->payable, 200_000);
    ledgerEntry('2026-08-05', $this->cash, $this->income, 100_000);
    ledgerEntry('2026-08-12', $this->cash, $this->income, 150_000, ['status' => JournalEntry::STATUS_DRAFT]);

    $entries = ledgerReports()->journal(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($entries)->toHaveCount(2)
        ->and($entries->first()->date->toDateString())->toBe('2026-08-05')
        ->and($entries->first()->lines)->toHaveCount(2)
        ->and($entries->last()->date->toDateString())->toBe('2026-08-20');
});

it('bounds the journal inclusively at both ends', function () {
    ledgerEntry('2026-07-31', $this->cash, $this->income, 100_000);
    ledgerEntry('2026-08-01', $this->cash, $this->income, 100_000);
    ledgerEntry('2026-08-31', $this->cash, $this->income, 100_000);
    ledgerEntry('2026-09-01', $this->cash, $this->income, 100_000);

    $entries = ledgerReports()->journal(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($entries)->toHaveCount(2);
});
