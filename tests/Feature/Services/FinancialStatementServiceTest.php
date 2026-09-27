<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Services\Accounting\Reports\AccountingSummaryService;
use App\Services\Accounting\Reports\BalanceSheet;
use App\Services\Accounting\Reports\FinancialStatementService;
use App\Services\Accounting\Reports\IncomeStatement;
use App\Services\Accounting\Reports\StatementColumnResolver;
use App\Services\Accounting\Reports\StatementLine;
use App\Support\Centavos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The Income Statement and the Balance Sheet (Phase 5 Slice 8b).
 *
 * The test that matters most is the first Balance Sheet one. Nothing in this
 * system closes a year, so the profit a school has made sits in its income
 * and expense accounts for ever, and a Balance Sheet that reads only the
 * equity accounts is short by exactly that much. Every other test here is a
 * consequence of getting that right.
 *
 * Fixtures are balanced two-line entries, as in `LedgerReportServiceTest`:
 * an unbalanced ledger would let a wrong figure look right.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->cash = ChartOfAccount::factory()->asset()->create([
        'code' => '1100', 'name' => 'Cash on Hand', 'subtype' => 'current_asset',
    ]);
    $this->equipment = ChartOfAccount::factory()->asset()->create([
        'code' => '1500', 'name' => 'Equipment', 'subtype' => 'non_current_asset',
        'cash_flow_category' => ChartOfAccount::CASH_FLOW_INVESTING,
    ]);
    $this->depreciation = ChartOfAccount::factory()->asset()->create([
        'code' => '1520', 'name' => 'Accumulated Depreciation', 'subtype' => 'contra_asset',
        'cash_flow_category' => ChartOfAccount::CASH_FLOW_INVESTING,
    ]);
    $this->payable = ChartOfAccount::factory()->liability()->create([
        'code' => '2100', 'name' => 'Accounts Payable', 'subtype' => 'current_liability',
    ]);
    $this->capital = ChartOfAccount::factory()->equity()->create([
        'code' => '3100', 'name' => "Owner's Capital", 'subtype' => 'equity',
    ]);
    $this->retained = ChartOfAccount::factory()->equity()->create([
        'code' => '3200', 'name' => 'Retained Earnings', 'subtype' => 'equity',
        'system_code' => ChartOfAccount::SYSTEM_RETAINED_EARNINGS,
    ]);
    $this->tuition = ChartOfAccount::factory()->income()->create([
        'code' => '4100', 'name' => 'Tuition Fee Income', 'subtype' => 'operating_revenue',
    ]);
    $this->interest = ChartOfAccount::factory()->income()->create([
        'code' => '4900', 'name' => 'Interest Income', 'subtype' => 'other_income',
    ]);
    $this->salaries = ChartOfAccount::factory()->expense()->create([
        'code' => '5100', 'name' => 'Salaries and Wages', 'subtype' => 'operating_expense',
    ]);
    $this->depreciationExpense = ChartOfAccount::factory()->expense()->create([
        'code' => '5400', 'name' => 'Depreciation Expense', 'subtype' => 'operating_expense',
    ]);
});

function financialStatements(): FinancialStatementService
{
    return app(FinancialStatementService::class);
}

/** @param array<string, mixed> $attributes */
function statementEntry(
    string $date,
    ChartOfAccount $debit,
    ChartOfAccount $credit,
    int $centavos,
    array $attributes = [],
): JournalEntry {
    $entry = JournalEntry::factory()->create([
        'date' => CarbonImmutable::parse($date),
        'status' => JournalEntry::STATUS_POSTED,
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

function statementLineByKey(IncomeStatement|BalanceSheet $statement, string $key): ?StatementLine
{
    foreach ($statement->lines as $line) {
        if ($line->key === $key) {
            return $line;
        }
    }

    return null;
}

function incomeStatementFor(
    string $from,
    string $to,
    string $mode = 'none',
    ?string $compareFrom = null,
    ?string $compareTo = null,
    bool $includeZero = false,
): IncomeStatement {
    $columns = (new StatementColumnResolver)->resolve(
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
        $mode,
        $compareFrom === null ? null : CarbonImmutable::parse($compareFrom),
        $compareTo === null ? null : CarbonImmutable::parse($compareTo),
    );

    return financialStatements()->incomeStatement($columns, $includeZero);
}

/**
 * Two years of trading with no year-end close between them.
 *
 * 2025: ₱1,000 capital paid in, ₱5,000 tuition, ₱3,000 salaries — ₱2,000 profit.
 * 2026: ₱8,000 tuition, ₱4,500 salaries, equipment bought on credit for
 *       ₱2,000 and depreciated by ₱500 — ₱3,000 profit.
 */
function tradeForTwoYears(): void
{
    $t = test();

    statementEntry('2025-01-05', $t->cash, $t->capital, 100_000);
    statementEntry('2025-06-10', $t->cash, $t->tuition, 500_000);
    statementEntry('2025-06-30', $t->salaries, $t->cash, 300_000);

    statementEntry('2026-02-10', $t->cash, $t->tuition, 800_000);
    statementEntry('2026-02-28', $t->salaries, $t->cash, 450_000);
    statementEntry('2026-03-01', $t->equipment, $t->payable, 200_000);
    statementEntry('2026-03-31', $t->depreciationExpense, $t->depreciation, 50_000);
}

/* ── Balance Sheet: the earnings that no account holds ──────────────── */

it('balances when earlier years were never closed into retained earnings', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));

    // Cash 1,000 + 5,000 − 3,000 + 8,000 − 4,500 = 6,500
    // Equipment 2,000 less depreciation 500 = 1,500
    expect($sheet->primary()->assetsCentavos)->toBe(800_000)
        ->and($sheet->primary()->liabilitiesCentavos)->toBe(200_000)
        // Capital 1,000 + 2025 profit 2,000 + 2026 profit 3,000
        ->and($sheet->primary()->equityCentavos)->toBe(600_000)
        ->and($sheet->primary()->varianceCentavos())->toBe(0)
        ->and($sheet->isBalanced())->toBeTrue();
});

it('puts earlier years in retained earnings and this year on its own line', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));

    expect(statementLineByKey($sheet, 'retained_earnings')->amountsCentavos)->toBe([200_000])
        ->and(statementLineByKey($sheet, 'current_year_earnings')->amountsCentavos)->toBe([300_000]);
});

it('itemises retained earnings when part of it is unclosed profit', function () {
    tradeForTwoYears();
    // A balance genuinely on the account, so both parts are non-zero.
    statementEntry('2025-01-05', $this->cash, $this->retained, 70_000);

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));

    $total = statementLineByKey($sheet, 'retained_earnings');
    $ledger = statementLineByKey($sheet, 'retained_earnings:ledger');
    $unclosed = statementLineByKey($sheet, 'retained_earnings:unclosed');

    expect($total->amountsCentavos)->toBe([270_000])
        // No link on the combined figure: the ledger would show 700, not 2,700.
        ->and($total->drill)->toBeNull()
        ->and($ledger->amountsCentavos)->toBe([70_000])
        ->and($ledger->drill['report'])->toBe('general-ledger')
        ->and($unclosed->amountsCentavos)->toBe([200_000])
        ->and($unclosed->drill)->toBe([
            'report' => 'income-statement',
            'from' => '2025-01-05',
            'to' => '2025-12-31',
        ])
        ->and($sheet->isBalanced())->toBeTrue();
});

it('links retained earnings straight to its ledger when nothing is unclosed', function () {
    statementEntry('2026-01-05', $this->cash, $this->retained, 70_000);

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));

    expect(statementLineByKey($sheet, 'retained_earnings')->drill['report'])->toBe('general-ledger')
        ->and(statementLineByKey($sheet, 'retained_earnings:ledger'))->toBeNull()
        ->and(statementLineByKey($sheet, 'retained_earnings:unclosed'))->toBeNull();
});

it('still balances when the school has no retained earnings account', function () {
    tradeForTwoYears();
    $this->retained->delete();

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));
    $line = statementLineByKey($sheet, 'retained_earnings');

    expect($line->kind)->toBe(StatementLine::KIND_COMPUTED)
        ->and($line->accountId)->toBeNull()
        ->and($line->amountsCentavos)->toBe([200_000])
        ->and($sheet->isBalanced())->toBeTrue();
});

it('splits the years where the fiscal year starts, not the calendar', function () {
    tradeForTwoYears();

    // A fiscal year running July to June. February 2026 now belongs to the
    // year that began in July 2025, and June 2025 to the one before it.
    foreach (range(0, 11) as $offset) {
        AccountingPeriod::factory()
            ->forMonth(CarbonImmutable::parse('2025-07-01')->addMonths($offset))
            ->create(['fiscal_year' => 2026]);
    }

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-06-30'));

    expect($sheet->primary()->fiscalYearStart->toDateString())->toBe('2025-07-01')
        ->and(statementLineByKey($sheet, 'retained_earnings')->amountsCentavos)->toBe([200_000])
        ->and(statementLineByKey($sheet, 'current_year_earnings')->amountsCentavos)->toBe([300_000])
        ->and(statementLineByKey($sheet, 'current_year_earnings')->drill)->toBe([
            'report' => 'income-statement',
            'from' => '2025-07-01',
            'to' => '2026-06-30',
        ])
        ->and($sheet->isBalanced())->toBeTrue();
});

it('falls back to the calendar year when no periods are defined', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-08-15'));

    expect($sheet->primary()->fiscalYearStart->toDateString())->toBe('2026-01-01');
});

it('reports the position as at the date, ignoring what came after', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2025-12-31'));

    // Only 2025 has happened: cash 3,000, capital 1,000, profit 2,000.
    expect($sheet->primary()->assetsCentavos)->toBe(300_000)
        ->and($sheet->primary()->liabilitiesCentavos)->toBe(0)
        ->and(statementLineByKey($sheet, 'current_year_earnings')->amountsCentavos)->toBe([200_000])
        ->and(statementLineByKey($sheet, 'account:'.$this->equipment->getKey()))->toBeNull()
        ->and($sheet->isBalanced())->toBeTrue();
});

it('includes entries dated on the as-at day itself', function () {
    statementEntry('2026-03-31', $this->cash, $this->capital, 100_000);

    expect(financialStatements()->balanceSheet(CarbonImmutable::parse('2026-03-31'))->primary()->assetsCentavos)
        ->toBe(100_000);
});

it('is all zeros, and balanced, before anything was posted', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2024-12-31'));

    expect($sheet->primary()->assetsCentavos)->toBe(0)
        ->and($sheet->primary()->equityCentavos)->toBe(0)
        ->and($sheet->isBalanced())->toBeTrue();
});

it('leaves drafts out of the balance sheet', function () {
    statementEntry('2026-03-01', $this->cash, $this->capital, 100_000);
    statementEntry('2026-03-02', $this->cash, $this->capital, 999_999, [
        'status' => JournalEntry::STATUS_DRAFT,
    ]);

    expect(financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'))->primary()->assetsCentavos)
        ->toBe(100_000);
});

it('says so when the ledger itself does not balance', function () {
    // A line that reached the ledger without an opposite side — something
    // posting would never allow.
    $entry = JournalEntry::factory()->create([
        'date' => CarbonImmutable::parse('2026-03-01'),
        'status' => JournalEntry::STATUS_POSTED,
    ]);
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $this->cash->getKey(),
        'debit_centavos' => 12_345,
        'credit_centavos' => 0,
        'line_number' => 1,
    ]);

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));

    expect($sheet->isBalanced())->toBeFalse()
        ->and($sheet->primary()->varianceCentavos())->toBe(12_345);
});

/* ── Balance Sheet: layout ──────────────────────────────────────────── */

it('prints a contra asset as a negative beside the asset it reduces', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));
    $depreciation = statementLineByKey($sheet, 'account:'.$this->depreciation->getKey());

    expect($depreciation->section)->toBe('non_current_assets')
        ->and($depreciation->amountsCentavos)->toBe([-50_000])
        ->and(statementLineByKey($sheet, 'subtotal:non_current_assets')->amountsCentavos)->toBe([150_000]);
});

it('puts a contra asset outside investing with the current assets', function () {
    $allowance = ChartOfAccount::factory()->asset()->create([
        'code' => '1210', 'name' => 'Allowance for Doubtful Accounts', 'subtype' => 'contra_asset',
        'cash_flow_category' => ChartOfAccount::CASH_FLOW_OPERATING,
    ]);
    statementEntry('2026-03-01', $this->salaries, $allowance, 10_000);

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));

    expect(statementLineByKey($sheet, 'account:'.$allowance->getKey())->section)->toBe('current_assets');
});

it('keeps an account with no subtype, and marks it unclassified', function () {
    $odd = ChartOfAccount::factory()->liability()->create([
        'code' => '2900', 'name' => 'Sundry Creditors', 'subtype' => null,
    ]);
    statementEntry('2026-03-01', $this->cash, $odd, 40_000);

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));
    $line = statementLineByKey($sheet, 'account:'.$odd->getKey());

    expect($line->section)->toBe('current_liabilities')
        ->and($line->isUnclassified)->toBeTrue()
        ->and($sheet->isBalanced())->toBeTrue();
});

it('prints a retired account that still holds a balance', function () {
    statementEntry('2026-03-01', $this->cash, $this->payable, 40_000);
    $this->payable->update(['is_active' => false]);

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));
    $line = statementLineByKey($sheet, 'account:'.$this->payable->getKey());

    expect($line)->not->toBeNull()
        ->and($line->isActive)->toBeFalse()
        ->and($sheet->isBalanced())->toBeTrue();
});

it('hides nil balances unless they are asked for', function () {
    statementEntry('2026-03-01', $this->cash, $this->capital, 100_000);

    $asAt = CarbonImmutable::parse('2026-12-31');
    $key = 'account:'.$this->payable->getKey();

    expect(statementLineByKey(financialStatements()->balanceSheet($asAt), $key))->toBeNull()
        ->and(statementLineByKey(financialStatements()->balanceSheet($asAt, includeZero: true), $key))
        ->not->toBeNull();
});

it('links each account to its ledger from the start of the fiscal year', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-08-15'));

    expect(statementLineByKey($sheet, 'account:'.$this->cash->getKey())->drill)->toBe([
        'report' => 'general-ledger',
        'from' => '2026-01-01',
        'to' => '2026-08-15',
    ]);
});

it('prints the sections in statement order', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));

    $structure = array_values(array_filter(array_map(
        fn (StatementLine $line): ?string => in_array($line->kind, ['heading', 'subtotal', 'total'], true)
            ? $line->key
            : null,
        $sheet->lines,
    )));

    expect($structure)->toBe([
        'heading:current_assets', 'subtotal:current_assets',
        'heading:non_current_assets', 'subtotal:non_current_assets',
        'total_assets',
        'heading:current_liabilities', 'subtotal:current_liabilities',
        'total_liabilities',
        'heading:equity', 'subtotal:equity',
        'total_liabilities_and_equity',
    ]);
});

/* ── Income Statement ───────────────────────────────────────────────── */

it('reports what was earned and spent inside the range only', function () {
    tradeForTwoYears();

    $statement = incomeStatementFor('2026-01-01', '2026-12-31');

    expect(statementLineByKey($statement, 'subtotal:revenue')->amountsCentavos)->toBe([800_000])
        ->and(statementLineByKey($statement, 'subtotal:operating_expenses')->amountsCentavos)->toBe([500_000])
        ->and(statementLineByKey($statement, 'operating_income')->amountsCentavos)->toBe([300_000])
        ->and($statement->netIncomeCentavos)->toBe([300_000]);
});

it('agrees with the accounting dashboard to the centavo', function () {
    // Two readers of the same ledger. If they ever differ, one of them is
    // wrong and nobody can tell which from the screen.
    tradeForTwoYears();
    statementEntry('2026-05-01', $this->cash, $this->interest, 12_345);

    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-12-31');

    $summary = app(AccountingSummaryService::class)->forRange($from, $to);

    expect(incomeStatementFor('2026-01-01', '2026-12-31')->netIncomeCentavos[0])
        ->toBe($summary->netIncomeCentavos());
});

it('keeps other income below operating income', function () {
    tradeForTwoYears();
    statementEntry('2026-05-01', $this->cash, $this->interest, 20_000);

    $statement = incomeStatementFor('2026-01-01', '2026-12-31');

    expect(statementLineByKey($statement, 'operating_income')->amountsCentavos)->toBe([300_000])
        ->and(statementLineByKey($statement, 'subtotal:other_income')->amountsCentavos)->toBe([20_000])
        ->and($statement->netIncomeCentavos)->toBe([320_000]);
});

it('leaves out an "other" section with nothing in it', function () {
    tradeForTwoYears();

    $statement = incomeStatementFor('2026-01-01', '2026-12-31');

    expect(statementLineByKey($statement, 'heading:other_income'))->toBeNull()
        ->and(statementLineByKey($statement, 'heading:other_expenses'))->toBeNull();
});

it('includes the last day of the range and leaves out drafts', function () {
    statementEntry('2026-07-31', $this->cash, $this->tuition, 100_000);
    statementEntry('2026-08-01', $this->cash, $this->tuition, 5_000);
    statementEntry('2026-07-15', $this->cash, $this->tuition, 999_999, [
        'status' => JournalEntry::STATUS_DRAFT,
    ]);

    expect(incomeStatementFor('2026-07-01', '2026-07-31')->netIncomeCentavos)->toBe([100_000]);
});

it('prints an account that did not move only when asked', function () {
    tradeForTwoYears();

    $key = 'account:'.$this->interest->getKey();

    expect(statementLineByKey(incomeStatementFor('2026-01-01', '2026-12-31'), $key))->toBeNull()
        ->and(statementLineByKey(incomeStatementFor('2026-01-01', '2026-12-31', includeZero: true), $key))
        ->not->toBeNull();
});

it('keeps an income account with no subtype, under revenue', function () {
    $odd = ChartOfAccount::factory()->income()->create(['code' => '4500', 'name' => 'Donations']);
    statementEntry('2026-05-01', $this->cash, $odd, 30_000);

    $line = statementLineByKey(incomeStatementFor('2026-01-01', '2026-12-31'), 'account:'.$odd->getKey());

    expect($line->section)->toBe('revenue')->and($line->isUnclassified)->toBeTrue();
});

/* ── Income Statement: comparison ───────────────────────────────────── */

it('compares against last year and states the change', function () {
    tradeForTwoYears();

    $statement = incomeStatementFor('2026-01-01', '2026-12-31', 'previous_year');
    $tuition = statementLineByKey($statement, 'account:'.$this->tuition->getKey());

    expect($tuition->amountsCentavos)->toBe([800_000, 500_000])
        ->and($tuition->varianceCentavos)->toBe(300_000)
        ->and($tuition->varianceBasisPoints)->toBe(6000)
        ->and($tuition->varianceFavourable)->toBeTrue()
        ->and($statement->netIncomeCentavos)->toBe([300_000, 200_000]);
});

it('counts a rise in an expense as unfavourable', function () {
    tradeForTwoYears();

    $statement = incomeStatementFor('2026-01-01', '2026-12-31', 'previous_year');
    $salaries = statementLineByKey($statement, 'account:'.$this->salaries->getKey());

    expect($salaries->varianceCentavos)->toBe(150_000)
        ->and($salaries->varianceFavourable)->toBeFalse()
        ->and(statementLineByKey($statement, 'subtotal:operating_expenses')->varianceFavourable)->toBeFalse()
        // Profit still rose, and that is good news.
        ->and(statementLineByKey($statement, 'net_income')->varianceFavourable)->toBeTrue();
});

it('has no percentage for a line that was nil in the comparison', function () {
    tradeForTwoYears();

    $statement = incomeStatementFor('2026-01-01', '2026-12-31', 'previous_year');
    $line = statementLineByKey($statement, 'account:'.$this->depreciationExpense->getKey());

    expect($line->amountsCentavos)->toBe([50_000, 0])
        ->and($line->varianceCentavos)->toBe(50_000)
        ->and($line->varianceBasisPoints)->toBeNull();
});

it('prints an account that moved only in the comparison column', function () {
    statementEntry('2025-03-01', $this->cash, $this->interest, 9_000);
    statementEntry('2026-03-01', $this->cash, $this->tuition, 100_000);

    $statement = incomeStatementFor('2026-01-01', '2026-12-31', 'previous_year');

    expect(statementLineByKey($statement, 'account:'.$this->interest->getKey())->amountsCentavos)
        ->toBe([0, 9_000]);
});

it('compares against a range the reader chose', function () {
    tradeForTwoYears();

    $statement = incomeStatementFor('2026-02-01', '2026-02-28', 'custom', '2025-06-01', '2025-06-30');

    expect($statement->netIncomeCentavos)->toBe([350_000, 200_000]);
});

it('prints a column a month whose total is the sum of the months', function () {
    tradeForTwoYears();

    $statement = incomeStatementFor('2026-01-01', '2026-03-31', 'monthly');
    $net = $statement->netIncomeCentavos;

    // January nothing; February 8,000 − 4,500; March depreciation 500.
    expect($net)->toBe([0, 350_000, -50_000, 300_000])
        ->and(array_sum(array_slice($net, 0, 3)))->toBe($net[3])
        ->and(statementLineByKey($statement, 'net_income')->varianceCentavos)->toBeNull();
});

it('prints retained earnings among the equity accounts, in code order', function () {
    tradeForTwoYears();
    $drawings = ChartOfAccount::factory()->equity()->create([
        'code' => '3300', 'name' => 'Dividends and Drawings', 'subtype' => 'contra_equity',
    ]);
    statementEntry('2026-04-01', $drawings, $this->cash, 10_000);

    $sheet = financialStatements()->balanceSheet(CarbonImmutable::parse('2026-12-31'));

    $equity = array_values(array_map(
        fn (StatementLine $line): string => $line->name,
        array_filter($sheet->lines, fn (StatementLine $line): bool => $line->section === 'equity'
            && in_array($line->kind, ['account', 'computed', 'detail'], true)),
    ));

    expect($equity)->toBe([
        "Owner's Capital",
        'Retained Earnings',
        'Ledger balance',
        'Prior-year profit not yet closed',
        'Dividends and Drawings',
        'Current-year earnings',
    ])->and($sheet->isBalanced())->toBeTrue();
});

/* ── Balance Sheet: two dates ───────────────────────────────────────── */

it('sets two dates side by side, each balancing on its own', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(
        CarbonImmutable::parse('2026-12-31'),
        compareAsAt: CarbonImmutable::parse('2025-12-31'),
    );

    expect($sheet->columns)->toHaveCount(2)
        ->and($sheet->primary()->assetsCentavos)->toBe(800_000)
        ->and($sheet->comparison()->assetsCentavos)->toBe(300_000)
        ->and($sheet->primary()->isBalanced())->toBeTrue()
        ->and($sheet->comparison()->isBalanced())->toBeTrue()
        ->and(statementLineByKey($sheet, 'total_assets')->amountsCentavos)->toBe([800_000, 300_000])
        ->and(statementLineByKey($sheet, 'total_liabilities_and_equity')->amountsCentavos)->toBe([800_000, 300_000]);
});

it('works out each date\'s earnings from that date\'s own fiscal year', function () {
    // As at the end of 2025, 2025 IS the current year: its ₱2,000 is
    // current-year earnings there, and retained earnings a year later.
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(
        CarbonImmutable::parse('2026-12-31'),
        compareAsAt: CarbonImmutable::parse('2025-12-31'),
    );

    expect($sheet->primary()->fiscalYearStart->toDateString())->toBe('2026-01-01')
        ->and($sheet->comparison()->fiscalYearStart->toDateString())->toBe('2025-01-01')
        ->and(statementLineByKey($sheet, 'retained_earnings')->amountsCentavos)->toBe([200_000, 0])
        ->and(statementLineByKey($sheet, 'current_year_earnings')->amountsCentavos)->toBe([300_000, 200_000]);
});

it('prints an account that held something on only one of the dates', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(
        CarbonImmutable::parse('2026-12-31'),
        compareAsAt: CarbonImmutable::parse('2025-12-31'),
    );

    // Bought in March 2026: nothing as at the end of 2025.
    expect(statementLineByKey($sheet, 'account:'.$this->equipment->getKey())->amountsCentavos)
        ->toBe([200_000, 0])
        ->and($sheet->isBalanced())->toBeTrue();
});

it('prints an account that held something only on the earlier date', function () {
    statementEntry('2025-06-01', $this->cash, $this->payable, 40_000);
    statementEntry('2026-02-01', $this->payable, $this->cash, 40_000);
    statementEntry('2025-01-05', $this->cash, $this->capital, 100_000);

    $sheet = financialStatements()->balanceSheet(
        CarbonImmutable::parse('2026-12-31'),
        compareAsAt: CarbonImmutable::parse('2025-12-31'),
    );

    expect(statementLineByKey($sheet, 'account:'.$this->payable->getKey())->amountsCentavos)
        ->toBe([0, 40_000])
        ->and($sheet->isBalanced())->toBeTrue();
});

it('states the change between the dates without calling it good or bad', function () {
    tradeForTwoYears();

    $sheet = financialStatements()->balanceSheet(
        CarbonImmutable::parse('2026-12-31'),
        compareAsAt: CarbonImmutable::parse('2025-12-31'),
    );
    $cash = statementLineByKey($sheet, 'account:'.$this->cash->getKey());

    // More cash and a bigger loan are both just larger.
    expect($cash->amountsCentavos)->toBe([650_000, 300_000])
        ->and($cash->varianceCentavos)->toBe(350_000)
        ->and($cash->varianceBasisPoints)->toBe(11667)
        ->and($cash->varianceFavourable)->toBeNull()
        ->and(statementLineByKey($sheet, 'total_assets')->varianceFavourable)->toBeNull();
});

it('keeps the print order when accounts come from different dates', function () {
    tradeForTwoYears();
    statementEntry('2025-06-01', $this->cash, $this->payable, 40_000);
    statementEntry('2026-02-01', $this->payable, $this->cash, 40_000);
    statementEntry('2026-03-01', $this->equipment, $this->payable, 1);

    $sheet = financialStatements()->balanceSheet(
        CarbonImmutable::parse('2026-12-31'),
        compareAsAt: CarbonImmutable::parse('2025-12-31'),
    );

    $codes = array_values(array_filter(array_map(
        fn (StatementLine $line): ?string => $line->kind === 'account' ? $line->code : null,
        $sheet->lines,
    )));

    expect($codes)->toBe(['1100', '1500', '1520', '2100', '3100', '3200']);
});

it('reports one date that does not balance beside one that does', function () {
    statementEntry('2025-06-01', $this->cash, $this->capital, 100_000);

    $entry = JournalEntry::factory()->create([
        'date' => CarbonImmutable::parse('2026-03-01'),
        'status' => JournalEntry::STATUS_POSTED,
    ]);
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $this->cash->getKey(),
        'debit_centavos' => 12_345,
        'credit_centavos' => 0,
        'line_number' => 1,
    ]);

    $sheet = financialStatements()->balanceSheet(
        CarbonImmutable::parse('2026-12-31'),
        compareAsAt: CarbonImmutable::parse('2025-12-31'),
    );

    expect($sheet->primary()->isBalanced())->toBeFalse()
        ->and($sheet->comparison()->isBalanced())->toBeTrue()
        ->and($sheet->isBalanced())->toBeFalse();
});

it('names the date each comparison means', function (string $mode, string $asAt, ?string $expected) {
    foreach (range(0, 11) as $offset) {
        AccountingPeriod::factory()
            ->forMonth(CarbonImmutable::parse('2025-07-01')->addMonths($offset))
            ->create(['fiscal_year' => 2026]);
    }

    $date = financialStatements()->comparisonDate(
        $mode,
        CarbonImmutable::parse($asAt),
        CarbonImmutable::parse('2025-11-15'),
    );

    expect($date?->toDateString())->toBe($expected);
})->with([
    'last month end' => ['previous_month_end', '2026-03-15', '2026-02-28'],
    'last month end, across a year' => ['previous_month_end', '2026-01-31', '2025-12-31'],
    'last month end, in a leap year' => ['previous_month_end', '2028-03-10', '2028-02-29'],
    // The fiscal year began 1 July 2025, so the one before ended 30 June.
    'last fiscal year end' => ['previous_year_end', '2026-03-15', '2025-06-30'],
    'a chosen date' => ['custom', '2026-03-15', '2025-11-15'],
    'none' => ['none', '2026-03-15', null],
]);

it('prints a peso figure without dividing', function (int $centavos, string $expected) {
    expect(Centavos::formatted($centavos))->toBe($expected);
})->with([
    [0, '0.00'],
    [5, '0.05'],
    [123_456_789, '1,234,567.89'],
    [-123_456_789, '(1,234,567.89)'],
    [-5, '(0.05)'],
]);
