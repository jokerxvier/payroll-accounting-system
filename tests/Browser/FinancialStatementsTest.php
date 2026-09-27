<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * The Balance Sheet and Income Statement, in a browser (Phase 5 Slice 8b).
 *
 * The feature tests prove the figures and the component tests prove each
 * page renders what it is given. Neither proves that clicking a figure on
 * one page arrives at the right figure on another, which is what drill-down
 * is — so that is what this file is for.
 *
 * See SmokeTest.php for how the browser suite is wired.
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    $this->cash = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
    $this->income = ChartOfAccount::query()->where('code', '4100')->firstOrFail();
});

function statementBrowserUser(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['accountant']);

    return $user;
}

function statementBrowserEntry(string $date, int $centavos): void
{
    $entry = JournalEntry::factory()->create([
        'entry_number' => 'JE-'.fake()->unique()->numerify('######'),
        'date' => CarbonImmutable::parse($date),
        'status' => JournalEntry::STATUS_POSTED,
        'narration' => 'Tuition collected',
        'total_debit_centavos' => $centavos,
        'total_credit_centavos' => $centavos,
    ]);

    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => test()->cash->getKey(),
        'debit_centavos' => $centavos,
        'credit_centavos' => 0,
        'line_number' => 1,
    ]);

    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => test()->income->getKey(),
        'debit_centavos' => 0,
        'credit_centavos' => $centavos,
        'line_number' => 2,
    ]);
}

it('shows a balance sheet that balances', function () {
    statementBrowserEntry('2026-08-15', 500_000);

    $this->actingAs(statementBrowserUser());

    visit('/admin/reports/balance-sheet?as_at=2026-08-31')
        ->assertSee('The statement balances.')
        ->assertSee('As at 31 Aug 2026')
        ->assertSee('Current-year earnings')
        ->assertSee('5,000.00')
        ->assertNoJavaScriptErrors();
});

it('opens an account\'s ledger from the balance sheet and comes back', function () {
    statementBrowserEntry('2026-08-15', 500_000);

    $this->actingAs(statementBrowserUser());

    visit('/admin/reports/balance-sheet?as_at=2026-08-31')
        ->click($this->cash->name)
        ->assertPathIs('/admin/reports/general-ledger')
        ->assertSee('General ledger')
        // The ledger's closing balance is the statement's figure.
        ->assertSee('5,000.00')
        ->click('Back')
        ->assertPathIs('/admin/reports/balance-sheet')
        ->assertSee('As at 31 Aug 2026')
        ->assertNoJavaScriptErrors();
});

it('opens the income statement from current-year earnings', function () {
    statementBrowserEntry('2026-08-15', 500_000);

    $this->actingAs(statementBrowserUser());

    visit('/admin/reports/balance-sheet?as_at=2026-08-31')
        ->click('Current-year earnings')
        ->assertPathIs('/admin/reports/income-statement')
        ->assertSee('1 Jan 2026 to 31 Aug 2026')
        ->assertSee('Net income')
        ->assertNoJavaScriptErrors();
});

it('sets two dates side by side on the balance sheet', function () {
    statementBrowserEntry('2026-07-15', 300_000);
    statementBrowserEntry('2026-08-15', 500_000);

    $this->actingAs(statementBrowserUser());

    visit('/admin/reports/balance-sheet?as_at=2026-08-31&compare=previous_month_end')
        ->assertSee('The statement balances as at 31 Aug 2026.')
        ->assertSee('The statement balances as at 31 Jul 2026.')
        ->assertSee('Variance')
        ->assertSee('+5,000.00')
        ->assertNoJavaScriptErrors();
});

it('shows the variance columns on a compared income statement', function () {
    statementBrowserEntry('2026-07-15', 300_000);
    statementBrowserEntry('2026-08-15', 500_000);

    $this->actingAs(statementBrowserUser());

    visit('/admin/reports/income-statement?preset=custom&from=2026-08-01&to=2026-08-31&compare=previous_period')
        ->assertSee('Aug 2026')
        ->assertSee('Jul 2026')
        ->assertSee('Variance')
        ->assertSee('+2,000.00')
        ->assertSee('+66.67%')
        ->assertNoJavaScriptErrors();
});
