<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\School;
use App\Services\Accounting\Reports\AccountingSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The accounting dashboard's figures.
 *
 * Two kinds of number, and the tests that matter are the ones proving they
 * stay apart: income and expenses are what moved INSIDE the range, while cash,
 * receivables and payables are what the school holds AS AT the end of it. A
 * dashboard reporting since-inception revenue under a "This Month" filter is
 * the failure this file exists to catch.
 *
 * Fixtures are balanced two-line entries for the same reason
 * `LedgerReportServiceTest` builds them that way — an unbalanced ledger would
 * let a wrong figure look right.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();
    Contact::query()->withoutGlobalScopes()->delete();

    $this->cash = ChartOfAccount::factory()->asset()->create([
        'code' => '1100',
        'name' => 'Cash on Hand',
        'is_cash_equivalent' => true,
    ]);
    $this->receivable = ChartOfAccount::factory()->asset()->create([
        'code' => '1200',
        'name' => 'Accounts Receivable',
        'system_code' => ChartOfAccount::SYSTEM_AR_CONTROL,
    ]);
    $this->payable = ChartOfAccount::factory()->liability()->create([
        'code' => '2100',
        'name' => 'Accounts Payable',
        'system_code' => ChartOfAccount::SYSTEM_AP_CONTROL,
    ]);
    $this->tuition = ChartOfAccount::factory()->income()->create([
        'code' => '4100',
        'name' => 'Tuition Fee Income',
    ]);
    $this->books = ChartOfAccount::factory()->income()->create([
        'code' => '4200',
        'name' => 'Books and Uniforms',
    ]);
    $this->salaries = ChartOfAccount::factory()->expense()->create([
        'code' => '5100',
        'name' => 'Salaries and Wages',
    ]);
});

function accountingSummary(): AccountingSummaryService
{
    return app(AccountingSummaryService::class);
}

/** @param array<string, mixed> $attributes */
function summaryEntry(
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

/* ── Period versus closing: the distinction the dashboard turns on ──── */

it('reports income earned inside the range, not since the books opened', function () {
    // THE test for this service. July's tuition must not appear in August's
    // figure — a "This Month" tile showing every peso ever billed is the
    // defining bug, and it is one method call away at all times.
    summaryEntry('2026-07-15', $this->receivable, $this->tuition, 300_000);
    summaryEntry('2026-08-15', $this->receivable, $this->tuition, 500_000);

    $august = accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($august->incomeCentavos)->toBe(500_000);
});

it('reports receivables as at the end of the range, opening included', function () {
    // The mirror image: a balance is a point in time, so July's unpaid
    // invoice is still owed in August and belongs in August's tile.
    summaryEntry('2026-07-15', $this->receivable, $this->tuition, 300_000);
    summaryEntry('2026-08-15', $this->receivable, $this->tuition, 500_000);

    $august = accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($august->receivablesCentavos)->toBe(800_000);
});

it('nets income against expenses for the range', function () {
    summaryEntry('2026-08-05', $this->receivable, $this->tuition, 900_000);
    summaryEntry('2026-08-20', $this->salaries, $this->cash, 350_000);

    $summary = accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($summary->incomeCentavos)->toBe(900_000)
        ->and($summary->expensesCentavos)->toBe(350_000)
        ->and($summary->netIncomeCentavos())->toBe(550_000);
});

it('reports a credit-normal income account as positive', function () {
    // Income is credit-normal, so `debits − credits` is negative for it. A
    // dashboard printing −₱9,000 of revenue would be arithmetically defensible
    // and useless.
    summaryEntry('2026-08-05', $this->receivable, $this->tuition, 900_000);

    expect(accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    )->incomeCentavos)->toBe(900_000);
});

/* ── What the tiles are made of ─────────────────────────────────────── */

it('counts only accounts flagged as cash', function () {
    // `is_cash_equivalent`, not "type is asset" — the receivable is an asset
    // too, and it is not money the school holds.
    summaryEntry('2026-08-05', $this->cash, $this->tuition, 400_000);
    summaryEntry('2026-08-06', $this->receivable, $this->tuition, 700_000);

    $summary = accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($summary->cashCentavos)->toBe(400_000)
        ->and($summary->receivablesCentavos)->toBe(700_000);
});

it('includes a payer whose receivable account is overridden', function () {
    // `ControlAccountResolver` lets a contact carry its own control account.
    // Summing the system code alone would quietly understate what the school
    // is owed, and the tile would be wrong in the school's favour.
    $ownAccount = ChartOfAccount::factory()->asset()->create([
        'code' => '1210',
        'name' => 'Receivable — Diocese',
    ]);
    Contact::factory()->create(['receivable_account_id' => $ownAccount->getKey()]);

    summaryEntry('2026-08-05', $this->receivable, $this->tuition, 200_000);
    summaryEntry('2026-08-06', $ownAccount, $this->tuition, 150_000);

    expect(accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    )->receivablesCentavos)->toBe(350_000);
});

it('reports a credit-normal payable as positive', function () {
    summaryEntry('2026-08-05', $this->salaries, $this->payable, 250_000);

    expect(accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    )->payablesCentavos)->toBe(250_000);
});

/* ── Revenue by account ─────────────────────────────────────────────── */

it('breaks revenue down by the accounts the school configured', function () {
    // Never a hardcoded list of fee types: the chart shows whatever is in the
    // chart of accounts, which is where a school says what it charges for.
    summaryEntry('2026-08-05', $this->receivable, $this->tuition, 900_000);
    summaryEntry('2026-08-06', $this->receivable, $this->books, 250_000);

    $revenue = accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    )->revenueByAccount;

    expect($revenue)->toHaveCount(2)
        ->and($revenue[0]['name'])->toBe('Tuition Fee Income')
        ->and($revenue[0]['centavos'])->toBe(900_000)
        ->and($revenue[1]['name'])->toBe('Books and Uniforms')
        ->and($revenue[1]['centavos'])->toBe(250_000);
});

it('leaves an income account that earned nothing out of the breakdown', function () {
    // A chart listing every account at zero is a chart nobody can read.
    summaryEntry('2026-08-05', $this->receivable, $this->tuition, 900_000);

    expect(accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    )->revenueByAccount)->toHaveCount(1);
});

/* ── What must never reach the figures ──────────────────────────────── */

it('ignores entries that never reached the ledger', function () {
    summaryEntry('2026-08-05', $this->receivable, $this->tuition, 900_000, [
        'status' => JournalEntry::STATUS_DRAFT,
    ]);
    summaryEntry('2026-08-06', $this->receivable, $this->tuition, 400_000, [
        'status' => JournalEntry::STATUS_VOIDED,
    ]);

    $summary = accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($summary->incomeCentavos)->toBe(0)
        ->and($summary->receivablesCentavos)->toBe(0);
});

it('keeps both halves of a reversal so they cancel', function () {
    // The original and its reversal are both posted, on purpose. Dropping the
    // original would leave the reversal unmatched and report negative revenue.
    summaryEntry('2026-08-05', $this->receivable, $this->tuition, 900_000);
    summaryEntry('2026-08-06', $this->tuition, $this->receivable, 900_000);

    expect(accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    )->incomeCentavos)->toBe(0);
});

it('ranges on the entry date, not on when it was posted', function () {
    // Backdated into August, keyed in September. It is August's revenue, or a
    // closed period's figures would move every time someone caught up.
    summaryEntry('2026-08-28', $this->receivable, $this->tuition, 250_000, [
        'posted_at' => CarbonImmutable::parse('2026-09-15'),
    ]);

    expect(accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    )->incomeCentavos)->toBe(250_000);
});

it('includes the last day of the range', function () {
    // The DayBoundary regression: a date column compared against a bare
    // 'Y-m-d' drops the final day under SQLite.
    summaryEntry('2026-08-31', $this->receivable, $this->tuition, 120_000);

    expect(accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    )->incomeCentavos)->toBe(120_000);
});

it('never reads another school\'s ledger', function () {
    // Reports are the widest tenancy surface in the codebase: every other
    // screen reads one document, these read the whole ledger at once.
    // Codes that cannot collide: `SchoolObserver` clones the default school's
    // catalogs onto every new school, so the usual 4100/1200 already exist
    // over there and creating them again hits the (school_id, code) unique.
    $other = School::factory()->create();
    $theirIncome = ChartOfAccount::factory()->income()->create([
        'school_id' => $other->getKey(),
        'code' => '8100',
    ]);
    $theirReceivable = ChartOfAccount::factory()->asset()->create([
        'school_id' => $other->getKey(),
        'code' => '8200',
    ]);

    $entry = JournalEntry::factory()->create([
        'school_id' => $other->getKey(),
        'date' => CarbonImmutable::parse('2026-08-10'),
        'status' => JournalEntry::STATUS_POSTED,
        'total_debit_centavos' => 9_999_900,
        'total_credit_centavos' => 9_999_900,
    ]);
    JournalEntryLine::factory()->create([
        'school_id' => $other->getKey(),
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $theirReceivable->getKey(),
        'debit_centavos' => 9_999_900,
        'credit_centavos' => 0,
    ]);
    JournalEntryLine::factory()->create([
        'school_id' => $other->getKey(),
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $theirIncome->getKey(),
        'debit_centavos' => 0,
        'credit_centavos' => 9_999_900,
        'line_number' => 2,
    ]);

    summaryEntry('2026-08-15', $this->receivable, $this->tuition, 100_000);

    $summary = accountingSummary()->forRange(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($summary->incomeCentavos)->toBe(100_000)
        ->and($summary->receivablesCentavos)->toBe(100_000);
});
