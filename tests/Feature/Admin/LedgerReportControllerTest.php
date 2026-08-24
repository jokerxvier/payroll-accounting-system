<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * /admin/reports/{trial-balance,general-ledger,journal-report} (Phase 5
 * Slice 8a).
 *
 * Pinned:
 *  - the role gate is AccountingRoles::VIEW, via JournalEntryPolicy::viewAny
 *  - all three formats come back, and an unknown format is refused
 *  - the general ledger needs an account chosen before it will export
 *  - an account id from another school reads as "not chosen", never as
 *    somebody else's ledger
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

function ledgerReportAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function postedLedgerEntry(string $date = '2026-08-15', int $centavos = 500_000): JournalEntry
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

/** Every report page, for the gate tests that treat them as one surface. */
function ledgerReportPaths(): array
{
    return [
        '/admin/reports/trial-balance',
        '/admin/reports/general-ledger',
        '/admin/reports/journal-report',
    ];
}

/* ── The gate ───────────────────────────────────────────────────────── */

it('lets every ledger-viewing role read the reports', function (string $role) {
    foreach (ledgerReportPaths() as $path) {
        $this->actingAs(ledgerReportAuthAs($role))->get($path)->assertOk();
    }
})->with(['super-admin', 'accountant', 'payroll-officer', 'auditor']);

it('lets a platform admin read the reports', function () {
    // Not listed in AccountingRoles — reaches the page through the
    // Gate::before short-circuit, the same way it reaches the journal.
    $admin = User::factory()->withoutLmsMirror()->create();
    $admin->syncRoles(['platform-admin']);
    $admin = $admin->fresh();

    foreach (ledgerReportPaths() as $path) {
        $this->actingAs($admin)->get($path)->assertOk();
    }
});

it('refuses roles outside the ledger-viewing set', function (string $role) {
    foreach (ledgerReportPaths() as $path) {
        $this->actingAs(ledgerReportAuthAs($role))->get($path)->assertForbidden();
    }
})->with(['hr', 'employee']);

it('refuses a guest', function () {
    foreach (ledgerReportPaths() as $path) {
        $this->get($path)->assertRedirect('/login');
    }
});

/* ── Trial balance ──────────────────────────────────────────────────── */

it('renders the trial balance with its totals', function () {
    postedLedgerEntry('2026-08-15', 500_000);

    $this->actingAs(ledgerReportAuthAs('accountant'))
        ->get('/admin/reports/trial-balance?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/reports/trial-balance')
            ->where('totals.is_balanced', true)
            ->where('totals.closing_debit_centavos', 500_000)
            ->where('totals.closing_credit_centavos', 500_000)
            ->has('rows', 2)
        );
});

it('shows the whole chart when include_empty is set', function () {
    postedLedgerEntry();

    $this->actingAs(ledgerReportAuthAs('accountant'))
        ->get('/admin/reports/trial-balance?from=2026-08-01&to=2026-08-31&include_empty=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.include_empty', true))
        // The seeded chart is far larger than the two accounts that moved.
        ->assertInertia(fn ($page) => $page->has('rows', ChartOfAccount::query()->count()));
});

it('flips an inverted date range instead of returning nothing', function () {
    postedLedgerEntry('2026-08-15');

    $this->actingAs(ledgerReportAuthAs('accountant'))
        ->get('/admin/reports/trial-balance?from=2026-08-31&to=2026-08-01')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', '2026-08-01')
            ->where('filters.to', '2026-08-31')
            ->where('totals.period_debit_centavos', 500_000)
        );
});

/* ── General ledger ─────────────────────────────────────────────────── */

it('renders an empty general ledger until an account is chosen', function () {
    postedLedgerEntry();

    $this->actingAs(ledgerReportAuthAs('accountant'))
        ->get('/admin/reports/general-ledger')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/reports/general-ledger')
            ->where('ledger', null)
            ->has('accountOptions')
        );
});

it('renders one account\'s ledger', function () {
    postedLedgerEntry('2026-08-15', 500_000);

    $this->actingAs(ledgerReportAuthAs('accountant'))
        ->get("/admin/reports/general-ledger?from=2026-08-01&to=2026-08-31&account_id={$this->cash->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ledger.account.code', '1100')
            ->where('ledger.opening_raw_centavos', 0)
            ->where('ledger.closing_raw_centavos', 500_000)
            ->has('ledger.lines', 1)
            ->where('ledger.lines.0.contra_accounts', ['4100 Tuition Fee Income'])
        );
});

it('treats another school\'s account id as no account at all', function () {
    postedLedgerEntry();

    $other = School::factory()->create(['slug' => 'ledger-report-controller-foreign']);
    $foreign = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $other->getKey())
        ->where('code', '1100')
        ->firstOrFail();

    // Not a 404 and not their ledger: the id simply does not resolve inside
    // this tenant, which is the same state as not having picked one.
    $this->actingAs(ledgerReportAuthAs('accountant'))
        ->get("/admin/reports/general-ledger?account_id={$foreign->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ledger', null));
});

it('refuses to export a general ledger with no account chosen', function () {
    $this->actingAs(ledgerReportAuthAs('accountant'))
        ->get('/admin/reports/general-ledger/export')
        ->assertNotFound();
});

/* ── Journal report ─────────────────────────────────────────────────── */

it('renders the journal report grouped by entry', function () {
    postedLedgerEntry('2026-08-10', 300_000);
    postedLedgerEntry('2026-08-20', 200_000);
    // A draft has not moved the ledger and must not appear.
    JournalEntry::factory()->create([
        'date' => CarbonImmutable::parse('2026-08-15'),
        'status' => JournalEntry::STATUS_DRAFT,
    ]);

    $this->actingAs(ledgerReportAuthAs('auditor'))
        ->get('/admin/reports/journal-report?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/reports/journal-report')
            ->has('entries', 2)
            ->has('entries.0.lines', 2)
            ->where('totals.entry_count', 2)
            ->where('totals.debit_centavos', 500_000)
        );
});

/* ── Exports ────────────────────────────────────────────────────────── */

it('exports every report in every format', function (string $path, string $format) {
    postedLedgerEntry('2026-08-15');

    $accountId = $this->cash->getKey();
    $query = "from=2026-08-01&to=2026-08-31&account_id={$accountId}&format={$format}";

    $response = $this->actingAs(ledgerReportAuthAs('accountant'))->get("{$path}?{$query}");

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain(".{$format}");
})->with([
    '/admin/reports/trial-balance/export',
    '/admin/reports/general-ledger/export',
    '/admin/reports/journal-report/export',
])->with(['xlsx', 'csv', 'pdf']);

it('refuses an unknown export format', function () {
    $this->actingAs(ledgerReportAuthAs('accountant'))
        ->get('/admin/reports/trial-balance/export?format=docx')
        ->assertStatus(422);
});

it('names the export file after the range', function () {
    postedLedgerEntry();

    $response = $this->actingAs(ledgerReportAuthAs('accountant'))
        ->get('/admin/reports/trial-balance/export?from=2026-08-01&to=2026-08-31&format=csv');

    expect($response->headers->get('content-disposition'))
        ->toContain('trial-balance_2026-08-01_2026-08-31.csv');
});
