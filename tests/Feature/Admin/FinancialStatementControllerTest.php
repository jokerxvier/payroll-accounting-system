<?php

declare(strict_types=1);

use App\Exports\BalanceSheetExport;
use App\Exports\IncomeStatementExport;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\Reports\FinancialStatementService;
use App\Services\Accounting\Reports\StatementColumnResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * /admin/reports/{balance-sheet,income-statement} (Phase 5 Slice 8b).
 *
 * Pinned:
 *  - the role gate is the ledger reports' gate, AccountingRoles::VIEW
 *  - filters are read leniently: a bad date or an impossible comparison
 *    falls back and says so, it does not error
 *  - all three formats come back for every comparison, and an unknown
 *    format is refused
 *
 * Run against the seeded chart rather than hand-built accounts, so the
 * section mapping is exercised on the subtypes a real school has.
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

function statementAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function postedStatementEntry(string $date = '2026-08-15', int $centavos = 500_000): JournalEntry
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

    return $entry;
}

/** @return list<string> */
function statementPaths(): array
{
    return [
        '/admin/reports/balance-sheet',
        '/admin/reports/income-statement',
        '/admin/reports/balance-sheet/export',
        '/admin/reports/income-statement/export',
    ];
}

/* ── The gate ───────────────────────────────────────────────────────── */

it('lets every ledger-viewing role read the statements', function (string $role) {
    foreach (statementPaths() as $path) {
        $this->actingAs(statementAuthAs($role))->get($path)->assertOk();
    }
})->with(['super-admin', 'accountant', 'payroll-officer', 'auditor']);

it('lets a platform admin read the statements', function () {
    $admin = User::factory()->withoutLmsMirror()->create();
    $admin->syncRoles(['platform-admin']);
    $admin = $admin->fresh();

    foreach (statementPaths() as $path) {
        $this->actingAs($admin)->get($path)->assertOk();
    }
});

it('refuses roles outside the ledger-viewing set', function (string $role) {
    foreach (statementPaths() as $path) {
        $this->actingAs(statementAuthAs($role))->get($path)->assertForbidden();
    }
})->with(['hr', 'employee']);

it('refuses a guest', function () {
    foreach (statementPaths() as $path) {
        $this->get($path)->assertRedirect('/login');
    }
});

/* ── Balance sheet ──────────────────────────────────────────────────── */

it('renders a balance sheet that balances', function () {
    postedStatementEntry('2026-08-15', 500_000);

    $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/balance-sheet?as_at=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/reports/balance-sheet')
            ->where('filters.as_at', '2026-08-31')
            ->where('filters.compare', 'none')
            ->where('hasVariance', false)
            ->has('columns', 1)
            ->where('columns.0.fiscal_year_start', '2026-01-01')
            ->where('columns.0.assets_centavos', 500_000)
            ->where('columns.0.liabilities_centavos', 0)
            // Nothing was posted to equity: all of it is this year's profit.
            ->where('columns.0.equity_centavos', 500_000)
            ->where('columns.0.is_balanced', true)
            ->has('lines')
        );
});

it('draws the balance sheet up as at today when no date is given', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 02:00:00', 'Asia/Manila'));

    $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/balance-sheet')
        ->assertOk()
        // 02:00 in Manila is still the 9th in UTC. The school's date wins.
        ->assertInertia(fn ($page) => $page->where('filters.as_at', '2026-09-10'));
});

it('falls back to today for a date it cannot read', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00', 'Asia/Manila'));

    $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/balance-sheet?as_at=not-a-date')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.as_at', '2026-09-10'));
});

/* ── Income statement ───────────────────────────────────────────────── */

it('renders the income statement for a custom range', function () {
    postedStatementEntry('2026-08-15', 500_000);

    $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/income-statement?preset=custom&from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/reports/income-statement')
            ->where('filters.from', '2026-08-01')
            ->where('filters.to', '2026-08-31')
            ->where('filters.compare', 'none')
            ->where('hasVariance', false)
            ->has('columns', 1)
            ->where('columns.0.label', 'Aug 2026')
            ->where('notices', [])
        );
});

it('opens on the current fiscal year', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00', 'Asia/Manila'));

    $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/income-statement')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.preset', 'year')
            ->where('filters.from', '2026-01-01')
            ->where('filters.to', '2026-12-31')
        );
});

it('adds a comparison column and its variance', function () {
    postedStatementEntry('2026-08-15', 500_000);
    postedStatementEntry('2026-07-15', 400_000);

    $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/income-statement?preset=custom&from=2026-08-01&to=2026-08-31&compare=previous_period')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.compare', 'previous_period')
            ->where('hasVariance', true)
            ->has('columns', 2)
            ->where('columns.1.from', '2026-07-01')
            ->where('columns.1.to', '2026-07-31')
        );
});

it('reports the comparison it applied when the one asked for was impossible', function () {
    // Custom with no dates: the page must come back showing "none" selected,
    // not "custom" over a statement that has no comparison column.
    $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/income-statement?preset=custom&from=2026-08-01&to=2026-08-31&compare=custom')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.compare', 'none')
            ->has('columns', 1)
            ->has('notices', 1)
        );
});

it('prints a column a month', function () {
    $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/income-statement?preset=custom&from=2026-01-01&to=2026-03-31&compare=monthly')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.compare', 'monthly')
            ->where('hasVariance', false)
            ->has('columns', 4)
            ->where('columns.3.role', 'total')
        );
});

/* ── Exports ────────────────────────────────────────────────────────── */

it('exports both statements in every format and comparison', function (string $path, string $format, string $compare) {
    postedStatementEntry('2026-08-15');

    $query = http_build_query([
        'as_at' => '2026-08-31',
        'preset' => 'custom',
        'from' => '2026-01-01',
        'to' => '2026-12-31',
        'compare' => $compare,
        'format' => $format,
    ]);

    $response = $this->actingAs(statementAuthAs('accountant'))->get("{$path}?{$query}");

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain(".{$format}");
})->with([
    '/admin/reports/balance-sheet/export',
    '/admin/reports/income-statement/export',
])->with(['xlsx', 'csv', 'pdf'])->with(['none', 'previous_year', 'monthly']);

it('refuses an unknown export format', function (string $path) {
    $this->actingAs(statementAuthAs('accountant'))
        ->get("{$path}?format=docx")
        ->assertStatus(422);
})->with([
    '/admin/reports/balance-sheet/export',
    '/admin/reports/income-statement/export',
]);

it('names the balance sheet export after its date', function () {
    $response = $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/balance-sheet/export?as_at=2026-08-31&format=csv');

    expect($response->headers->get('content-disposition'))->toContain('balance-sheet_2026-08-31.csv');
});

it('names the income statement export after its range and comparison', function () {
    $response = $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/income-statement/export?preset=custom&from=2026-08-01&to=2026-08-31&compare=previous_year&format=csv');

    expect($response->headers->get('content-disposition'))
        ->toContain('income-statement_2026-08-01_2026-08-31_vs-previous-year.csv');
});

it('writes a variable number of columns to the income statement file', function () {
    postedStatementEntry('2026-08-15', 500_000);
    postedStatementEntry('2025-08-15', 400_000);

    $columns = (new StatementColumnResolver)->resolve(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
        'previous_year',
    );
    $export = new IncomeStatementExport(app(FinancialStatementService::class)->incomeStatement($columns));

    $rows = $export->collection();
    $tuition = $rows->firstWhere(1, '4100');

    expect($export->headings())->toBe([
        'Section', 'Code', 'Account', 'Aug 2026', 'Aug 2025', 'Variance', 'Variance %',
    ])
        ->and($tuition)->toBe(['Revenue', '4100', $this->income->name, '5000.00', '4000.00', '1000.00', '25.00%'])
        ->and($rows->last()[2])->toBe('Net income');
});

it('closes the balance sheet file with the balance check', function () {
    postedStatementEntry('2026-08-15', 500_000);

    $export = new BalanceSheetExport(
        app(FinancialStatementService::class)->balanceSheet(CarbonImmutable::parse('2026-08-31')),
    );

    expect($export->headings())->toBe(['Section', 'Code', 'Account', 'As at 31 Aug 2026', 'Note'])
        ->and($export->collection()->last())->toBe([
            '', '', 'Assets less liabilities and equity', '0.00', 'Balanced',
        ]);
});

/* ── Balance sheet: comparison ──────────────────────────────────────── */

it('sets a second date beside the first', function (string $compare, string $expected) {
    postedStatementEntry('2026-07-15', 300_000);
    postedStatementEntry('2026-08-15', 500_000);

    $this->actingAs(statementAuthAs('accountant'))
        ->get("/admin/reports/balance-sheet?as_at=2026-08-31&compare={$compare}&compare_as_at=2026-07-20")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.compare', $compare)
            ->where('filters.compare_as_at', $expected)
            ->where('hasVariance', true)
            ->has('columns', 2)
            ->where('columns.0.as_at', '2026-08-31')
            ->where('columns.1.as_at', $expected)
            ->where('columns.0.is_balanced', true)
            ->where('columns.1.is_balanced', true)
        );
})->with([
    'the end of last month' => ['previous_month_end', '2026-07-31'],
    // No periods are defined, so the fiscal year is the calendar year.
    'the end of last year' => ['previous_year_end', '2025-12-31'],
    'a chosen date' => ['custom', '2026-07-20'],
]);

it('falls back to one date, and says so, when the comparison cannot be made', function (string $query) {
    $this->actingAs(statementAuthAs('accountant'))
        ->get("/admin/reports/balance-sheet?as_at=2026-08-31&{$query}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.compare', 'none')
            ->where('hasVariance', false)
            ->has('columns', 1)
            ->has('notices', 1)
        );
})->with([
    'no date chosen' => ['compare=custom'],
    'an unreadable date' => ['compare=custom&compare_as_at=not-a-date'],
    'the same date' => ['compare=custom&compare_as_at=2026-08-31'],
]);

it('treats an unknown comparison as none, without comment', function () {
    $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/balance-sheet?as_at=2026-08-31&compare=sideways')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.compare', 'none')
            ->where('notices', [])
        );
});

it('names the comparative balance sheet export after both dates', function () {
    $response = $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/balance-sheet/export?as_at=2026-08-31&compare=previous_month_end&format=csv');

    expect($response->headers->get('content-disposition'))
        ->toContain('balance-sheet_2026-08-31_vs-2026-07-31.csv');
});

it('writes both dates and their variance to the balance sheet file', function () {
    postedStatementEntry('2026-07-15', 300_000);
    postedStatementEntry('2026-08-15', 500_000);

    $export = new BalanceSheetExport(app(FinancialStatementService::class)->balanceSheet(
        CarbonImmutable::parse('2026-08-31'),
        compareAsAt: CarbonImmutable::parse('2026-07-31'),
    ));

    $rows = $export->collection();

    expect($export->headings())->toBe([
        'Section', 'Code', 'Account', 'As at 31 Aug 2026', 'As at 31 Jul 2026', 'Variance', 'Variance %', 'Note',
    ])
        ->and($rows->firstWhere(1, '1100'))->toBe([
            'Current assets', '1100', $this->cash->name, '8000.00', '3000.00', '5000.00', '166.67%', '',
        ])
        ->and($rows->last())->toBe([
            '', '', 'Assets less liabilities and equity', '0.00', '0.00', '', '', 'Balanced',
        ]);
});

/* ── Coming back from a drill-down ──────────────────────────────────── */

it('offers a way back to the report the reader came from', function (string $path) {
    $returnTo = '/admin/reports/balance-sheet?as_at=2026-08-31&include_zero=0';

    $this->actingAs(statementAuthAs('accountant'))
        ->get($path.'?'.http_build_query(['return_to' => $returnTo]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('backHref', $returnTo));
})->with([
    '/admin/reports/general-ledger',
    '/admin/reports/income-statement',
]);

it('ignores a return address that leaves the reports', function (string $returnTo) {
    // The value is whatever anyone puts in the query string, and a Back
    // button is a link the reader trusts.
    foreach (['/admin/reports/general-ledger', '/admin/reports/income-statement'] as $path) {
        $this->actingAs(statementAuthAs('accountant'))
            ->get($path.'?'.http_build_query(['return_to' => $returnTo]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('backHref', null));
    }
})->with([
    'another site' => ['https://evil.test/admin/reports/balance-sheet'],
    'a protocol-relative address' => ['//evil.test/admin/reports/'],
    'a script' => ['javascript:alert(1)'],
    'another part of the app' => ['/dashboard'],
    'a path that climbs out' => ['/admin/reports/../../logout'],
    'a double slash further in' => ['/admin/reports//evil.test'],
    'a backslash' => ['/admin/reports/\\evil.test'],
    'a newline' => ["/admin/reports/balance-sheet\nSet-Cookie: x=1"],
    'nothing' => [''],
]);

it('ignores a return address that is not a string', function () {
    $this->actingAs(statementAuthAs('accountant'))
        ->get('/admin/reports/general-ledger?return_to[]=/admin/reports/balance-sheet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('backHref', null));
});

it('reports the whole range month by month, not the first month of it', function () {
    // Month by month the first column is January. The statement's own dates,
    // and the file named after them, are still the quarter that was asked for.
    $query = 'preset=custom&from=2026-01-01&to=2026-03-31&compare=monthly';

    $this->actingAs(statementAuthAs('accountant'))
        ->get("/admin/reports/income-statement?{$query}")
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', '2026-01-01')
            ->where('filters.to', '2026-03-31')
        );

    $response = $this->actingAs(statementAuthAs('accountant'))
        ->get("/admin/reports/income-statement/export?{$query}&format=csv");

    expect($response->headers->get('content-disposition'))
        ->toContain('income-statement_2026-01-01_2026-03-31_vs-monthly.csv');
});
