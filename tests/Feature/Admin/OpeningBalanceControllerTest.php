<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\AuditLog;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Http\UploadedFile;

/*
 * /admin/opening-balances (Phase 5 Slice 9).
 *
 * Pinned:
 *  - the gate is AccountingRoles::POST_LEDGER, via
 *    JournalEntryPolicy::postOpeningBalance — narrower than the rest of the
 *    accounting module, and narrower than MANAGE
 *  - the preview writes nothing to the ledger, however bad the file is
 *  - a file with any bad row is refused whole, never part-applied
 *  - a stale preview token cannot be replayed
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->forMonth(CarbonImmutable::parse('2026-06-01'))->create();
});

function openingBalanceAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/**
 * A CSV in the template's shape. Built as a real upload rather than mocked
 * so the heading-row slugging maatwebsite applies is exercised too.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $rows
 */
function openingBalanceCsv(array $rows): UploadedFile
{
    $csv = "account_code,account_name (read-only),type (read-only),opening_debit,opening_credit\n";

    foreach ($rows as [$code, $debit, $credit]) {
        $csv .= sprintf("%s,name,type,%s,%s\n", $code, $debit, $credit);
    }

    $path = tempnam(sys_get_temp_dir(), 'ob').'.csv';
    file_put_contents($path, $csv);

    return new UploadedFile($path, 'opening-balances.csv', 'text/csv', null, true);
}

/** A sheet that foots: 700,000 cash against 700,000 retained earnings. */
function balancedCsv(): UploadedFile
{
    return openingBalanceCsv([
        ['1100', '700000.00', ''],
        ['3200', '', '700000.00'],
    ]);
}

/* ── The gate ───────────────────────────────────────────────────────── */

it('lets an accountant open the page', function (): void {
    $this->actingAs(openingBalanceAuthAs('accountant'))
        ->get(route('admin.opening-balances.index'))
        ->assertOk();
});

it('refuses a payroll officer, who may draft entries but not post the books open', function (): void {
    $this->actingAs(openingBalanceAuthAs('payroll-officer'))
        ->get(route('admin.opening-balances.index'))
        ->assertForbidden();
});

it('refuses an auditor', function (): void {
    $this->actingAs(openingBalanceAuthAs('auditor'))
        ->get(route('admin.opening-balances.index'))
        ->assertForbidden();
});

it('lets a platform admin through the Gate::before short-circuit', function (): void {
    $user = User::factory()->withoutLmsMirror()->create();
    $user->syncRoles(['platform-admin']);

    // ->fresh() because withoutLmsMirror() nulls lms_user_id in the
    // database after the model was built; the Gate::before short-circuit
    // reads it off the instance, so a stale one never gets the bypass.
    $this->actingAs($user->fresh())
        ->get(route('admin.opening-balances.index'))
        ->assertOk();
});

it('serves the template', function (): void {
    $this->actingAs(openingBalanceAuthAs('accountant'))
        ->get(route('admin.opening-balances.template'))
        ->assertOk();
});

/* ── Preview writes nothing ─────────────────────────────────────────── */

it('parses a preview without touching the ledger', function (): void {
    $this->actingAs(openingBalanceAuthAs('accountant'))
        ->post(route('admin.opening-balances.preview'), [
            'file' => balancedCsv(),
            'cutover_date' => '2026-06-30',
        ])
        ->assertRedirect(route('admin.opening-balances.index'));

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(session('opening_balance_import.parsed'))->toHaveCount(2);
});

it('reports an unknown account code as a row error rather than failing the upload', function (): void {
    $this->actingAs(openingBalanceAuthAs('accountant'))
        ->post(route('admin.opening-balances.preview'), [
            'file' => openingBalanceCsv([['9999', '100.00', '']]),
            'cutover_date' => '2026-06-30',
        ])
        ->assertRedirect(route('admin.opening-balances.index'));

    $parsed = session('opening_balance_import.parsed');

    expect($parsed[0]['errors'][0])->toContain('No account with code 9999');
});

it('reports an income account as a row error', function (): void {
    $this->actingAs(openingBalanceAuthAs('accountant'))
        ->post(route('admin.opening-balances.preview'), [
            'file' => openingBalanceCsv([['4100', '', '100.00']]),
            'cutover_date' => '2026-06-30',
        ]);

    $parsed = session('opening_balance_import.parsed');

    expect($parsed[0]['errors'][0])->toContain('Retained Earnings');
});

it('summarises the difference so the page can show it before confirm', function (): void {
    $user = openingBalanceAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.opening-balances.preview'), [
        'file' => openingBalanceCsv([['1100', '700000.00', ''], ['3200', '', '650000.00']]),
        'cutover_date' => '2026-06-30',
    ]);

    $this->actingAs($user)
        ->get(route('admin.opening-balances.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/opening-balances/index', false)
            ->where('summary.total_debit_centavos', 700_000_00)
            ->where('summary.total_credit_centavos', 650_000_00)
            ->where('summary.difference_centavos', 50_000_00)
            ->where('summary.error_count', 0)
            // June 2026 has an open period in beforeEach, so the only thing
            // standing between this sheet and the ledger is the 50,000.
            ->where('summary.period_is_open', true));
});

it('reports a cutover date outside any period as not open, before confirm', function (): void {
    $user = openingBalanceAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.opening-balances.preview'), [
        'file' => balancedCsv(),
        'cutover_date' => '2019-01-31',
    ]);

    $this->actingAs($user)
        ->get(route('admin.opening-balances.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/opening-balances/index', false)
            ->where('summary.difference_centavos', 0)
            ->where('summary.period_is_open', false));
});

it('reports the standing snapshot so the page can refuse a second one', function (): void {
    $user = openingBalanceAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.opening-balances.preview'), [
        'file' => balancedCsv(),
        'cutover_date' => '2026-06-30',
    ]);

    $this->actingAs($user)->post(
        route('admin.opening-balances.confirm', session('opening_balance_import.token')),
    );

    $this->actingAs($user)
        ->get(route('admin.opening-balances.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/opening-balances/index', false)
            ->where('existingSnapshot.date', '2026-06-30')
            ->whereNot('existingSnapshot.entry_number', null));
});

/* ── Confirm ────────────────────────────────────────────────────────── */

it('posts the snapshot on confirm', function (): void {
    $user = openingBalanceAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.opening-balances.preview'), [
        'file' => balancedCsv(),
        'cutover_date' => '2026-06-30',
    ]);

    $token = session('opening_balance_import.token');

    $this->actingAs($user)
        ->post(route('admin.opening-balances.confirm', $token))
        ->assertRedirectContains('journal-entries');

    $entry = JournalEntry::query()->openingBalance()->posted()->sole();

    expect($entry->total_debit_centavos)->toBe(700_000_00)
        ->and($entry->date->toDateString())->toBe('2026-06-30')
        ->and(session()->has('opening_balance_import'))->toBeFalse();
});

it('refuses the whole file when any row has an error', function (): void {
    $user = openingBalanceAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.opening-balances.preview'), [
        'file' => openingBalanceCsv([
            ['1100', '700000.00', ''],
            ['4100', '', '700000.00'], // income account
        ]),
        'cutover_date' => '2026-06-30',
    ]);

    $token = session('opening_balance_import.token');

    $this->actingAs($user)
        ->post(route('admin.opening-balances.confirm', $token))
        ->assertSessionHasErrors('file');

    expect(JournalEntry::query()->count())->toBe(0);
});

it('surfaces an unbalanced sheet as an error instead of a stack trace', function (): void {
    $user = openingBalanceAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.opening-balances.preview'), [
        'file' => openingBalanceCsv([['1100', '700000.00', ''], ['3200', '', '650000.00']]),
        'cutover_date' => '2026-06-30',
    ]);

    $token = session('opening_balance_import.token');

    $this->actingAs($user)
        ->post(route('admin.opening-balances.confirm', $token))
        ->assertSessionHasErrors('file');

    expect(JournalEntry::query()->count())->toBe(0);
});

it('plugs the difference when the user opts in', function (): void {
    $user = openingBalanceAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.opening-balances.preview'), [
        'file' => openingBalanceCsv([['1100', '700000.00', '']]),
        'cutover_date' => '2026-06-30',
    ]);

    $token = session('opening_balance_import.token');

    $this->actingAs($user)->post(route('admin.opening-balances.confirm', $token), [
        'plug_to_retained_earnings' => true,
    ]);

    $entry = JournalEntry::query()->openingBalance()->posted()->sole();

    expect($entry->total_debit_centavos)->toBe($entry->total_credit_centavos)
        ->and($entry->lines()->count())->toBe(2);
});

it('audits the accounts actually opened, not the rows uploaded', function (): void {
    $user = openingBalanceAuthAs('accountant');

    // Two real figures plus an untouched template row — which is the normal
    // shape of a returned worksheet, since the template lists every account.
    $this->actingAs($user)->post(route('admin.opening-balances.preview'), [
        'file' => openingBalanceCsv([
            ['1100', '700000.00', ''],
            ['1200', '0', '0'],
            ['3200', '', '700000.00'],
        ]),
        'cutover_date' => '2026-06-30',
    ]);

    $this->actingAs($user)->post(
        route('admin.opening-balances.confirm', session('opening_balance_import.token')),
    );

    $entry = JournalEntry::query()->openingBalance()->posted()->sole();
    $audit = AuditLog::query()
        ->where('action', 'accounting.opening_balances_imported')
        ->sole();

    expect($entry->lines()->count())->toBe(2)
        ->and($audit->after['accounts_opened'])->toBe(2);
});

it('rejects a stale preview token', function (): void {
    $user = openingBalanceAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.opening-balances.preview'), [
        'file' => balancedCsv(),
        'cutover_date' => '2026-06-30',
    ]);

    $this->actingAs($user)
        ->post(route('admin.opening-balances.confirm', 'not-the-token'))
        ->assertSessionHasErrors('token');

    expect(JournalEntry::query()->count())->toBe(0);
});

it('refuses a cutover date no open period covers, with the reason', function (): void {
    $user = openingBalanceAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.opening-balances.preview'), [
        'file' => balancedCsv(),
        'cutover_date' => '2019-01-31',
    ]);

    $token = session('opening_balance_import.token');

    $this->actingAs($user)
        ->post(route('admin.opening-balances.confirm', $token))
        ->assertSessionHasErrors('file');

    expect(JournalEntry::query()->count())->toBe(0);
});
