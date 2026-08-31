<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The accounting dashboard page.
 *
 * Its figures are covered by `AccountingSummaryServiceTest` and
 * `LedgerSeriesServiceTest`; what is left here is who may read them and which
 * dates the page opens on. Both matter: these are the school's profit and its
 * bank balance, and the default range is the only one most people will ever
 * look at.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccountingCatalogSeeder::class);
});

function dashboardAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

/* ── Who may read the books ─────────────────────────────────────────── */

it('lets every accounting-view role read the dashboard', function (string $role) {
    $this->actingAs(dashboardAuthAs($role))
        ->get(route('admin.reports.accounting-dashboard'))
        ->assertOk();
})->with(['super-admin', 'accountant', 'payroll-officer', 'auditor']);

it('refuses roles outside the accounting set', function (string $role) {
    // The school's profit and its bank balance. `/dashboard` is ungated
    // because it is payroll and HR; this is not that page.
    $this->actingAs(dashboardAuthAs($role))
        ->get(route('admin.reports.accounting-dashboard'))
        ->assertForbidden();
})->with(['hr', 'employee']);

it('lets a platform admin in through Gate::before', function () {
    // `withoutLmsMirror()` is required: the plain factory backfills
    // `lms_user_id` afterwards, and the bypass needs it null.
    $user = User::factory()->withoutLmsMirror()->create();
    $user->syncRoles(['platform-admin']);

    $this->actingAs($user->fresh())
        ->get(route('admin.reports.accounting-dashboard'))
        ->assertOk();
});

it('sends a guest to log in', function () {
    $this->get(route('admin.reports.accounting-dashboard'))
        ->assertRedirect('/login');
});

/* ── Which dates it opens on ────────────────────────────────────────── */

it('opens on the school\'s own fiscal year, not the calendar year', function () {
    // A school whose year runs June to March would be shown ten months of it
    // by a calendar default, and the tiles would understate everything.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15'));

    AccountingPeriod::factory()->create([
        'code' => '2027-06',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'fiscal_year' => 2027,
    ]);
    AccountingPeriod::factory()->create([
        'code' => '2027-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'fiscal_year' => 2027,
    ]);
    AccountingPeriod::factory()->create([
        'code' => '2027-03',
        'start_date' => '2027-03-01',
        'end_date' => '2027-03-31',
        'fiscal_year' => 2027,
    ]);

    $this->actingAs(dashboardAuthAs('accountant'))
        ->get(route('admin.reports.accounting-dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('filters.preset', 'year')
            ->where('filters.from', '2026-06-01')
            ->where('filters.to', '2027-03-31'));

    CarbonImmutable::setTestNow();
});

it('falls back to the calendar year when no period covers today', function () {
    // A school on its first day has no periods. The dashboard still has to
    // render rather than refusing until someone sets one up.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15'));

    $this->actingAs(dashboardAuthAs('accountant'))
        ->get(route('admin.reports.accounting-dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', '2026-01-01')
            ->where('filters.to', '2026-12-31'));

    CarbonImmutable::setTestNow();
});

it('narrows to this month when asked', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15'));

    $this->actingAs(dashboardAuthAs('accountant'))
        ->get(route('admin.reports.accounting-dashboard', ['preset' => 'month']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', '2026-08-01')
            ->where('filters.to', '2026-08-31'));

    CarbonImmutable::setTestNow();
});

it('takes a custom range', function () {
    $this->actingAs(dashboardAuthAs('accountant'))
        ->get(route('admin.reports.accounting-dashboard', [
            'preset' => 'custom',
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', '2026-08-01')
            ->where('filters.to', '2026-08-31'));
});

it('flips an inverted range instead of returning nothing', function () {
    // Same forgiveness the ledger reports show: someone who picked the dates
    // the wrong way round meant the range between them.
    $this->actingAs(dashboardAuthAs('accountant'))
        ->get(route('admin.reports.accounting-dashboard', [
            'preset' => 'custom',
            'from' => '2026-08-31',
            'to' => '2026-08-01',
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', '2026-08-01')
            ->where('filters.to', '2026-08-31'));
});

it('ignores an unreadable preset rather than erroring', function () {
    $this->actingAs(dashboardAuthAs('accountant'))
        ->get(route('admin.reports.accounting-dashboard', ['preset' => 'fortnight']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.preset', 'year'));
});

/* ── What it ships to the page ──────────────────────────────────────── */

it('ships every tile and a dense monthly series', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15'));

    $this->actingAs(dashboardAuthAs('accountant'))
        ->get(route('admin.reports.accounting-dashboard', [
            'preset' => 'custom',
            'from' => '2026-06-01',
            'to' => '2026-08-31',
        ]))
        ->assertInertia(fn ($page) => $page
            ->has('summary.cash_centavos')
            ->has('summary.receivables_centavos')
            ->has('summary.payables_centavos')
            ->has('summary.income_centavos')
            ->has('summary.expenses_centavos')
            ->has('summary.net_income_centavos')
            ->has('summary.revenue_by_account')
            // Three months in the range, all present even with nothing posted.
            ->has('monthlySeries', 3));

    CarbonImmutable::setTestNow();
});
