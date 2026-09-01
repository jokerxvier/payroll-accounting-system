<?php

declare(strict_types=1);

use App\Exports\ChartOfAccountExport;
use App\Exports\ChartOfAccountTemplateExport;
use App\Models\Pas\ChartOfAccount;
use App\Models\User;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/*
 * The chart's spreadsheet round trip.
 *
 * The chart is the one register where a bad bulk edit is not undone by
 * re-uploading: every posted journal line points at an account id, so flipping
 * a type re-signs figures already reported. So beyond the no-op round trip,
 * what needs pinning is what the sheet is NOT allowed to do — set a normal
 * balance, move a system code, unlock a locked row, or retype one.
 */

beforeEach(function (): void {
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->syncRoles(['accountant']);
});

/**
 * A CSV in the export's shape, uploaded for real so the heading slugging
 * maatwebsite applies is exercised rather than assumed.
 *
 * @param  list<array<int, string>>  $rows
 */
function chartCsv(array $rows): UploadedFile
{
    $csv = 'code (do not change),name,type,subtype,parent_code,cash_flow_category,'
        .'is_cash_equivalent,is_active,description,normal_balance (read-only),'
        ."system_code (read-only),is_locked (read-only)\n";

    foreach ($rows as $row) {
        $csv .= implode(',', array_pad($row, 12, ''))."\n";
    }

    $path = tempnam(sys_get_temp_dir(), 'coa').'.csv';
    file_put_contents($path, $csv);

    return new UploadedFile($path, 'chart.csv', 'text/csv', null, true);
}

/** @param list<array<int, string>> $rows */
function previewChart(array $rows): TestResponse
{
    return test()->actingAs(test()->actor)->post('/admin/chart-of-accounts/import/preview', [
        'file' => chartCsv($rows),
    ]);
}

function confirmChart(): TestResponse
{
    return test()->actingAs(test()->actor)
        ->post('/admin/chart-of-accounts/import/confirm/'.session('chart_import.token'));
}

function accountByCode(string $code): ChartOfAccount
{
    return ChartOfAccount::query()->where('code', $code)->sole();
}

/* ── Access ──────────────────────────────────────────────────────────── */

it('lets a read-only role export but not import', function () {
    $auditor = User::factory()->create();
    $auditor->syncRoles(['auditor']);

    $this->actingAs($auditor)->get('/admin/chart-of-accounts/export')->assertOk();
    $this->actingAs($auditor)
        ->get('/admin/chart-of-accounts/import/template')
        ->assertForbidden();
});

/* ── Export ──────────────────────────────────────────────────────────── */

it('exports the chart as a spreadsheet', function () {
    $this->actingAs($this->actor)
        ->get('/admin/chart-of-accounts/export')
        ->assertOk()
        ->assertHeader(
            'content-disposition',
            'attachment; filename=chart-of-accounts.xlsx',
        );
});

it('offers a template with the same columns as the export', function () {
    expect((new ChartOfAccountTemplateExport)->headings())
        ->toBe((new ChartOfAccountExport)->headings());
});

/* ── The dialog opening ──────────────────────────────────────────────── */

it('shows the preview once, not on every later visit to the chart', function () {
    // The dialog opens because `import` is present in the props. The parsed
    // rows have to stay in the session until confirm can read them — but if
    // their PRESENCE is what opens the dialog, an abandoned preview reopens it
    // on every visit to a page people are on constantly.
    previewChart([
        ['9001', 'Never Confirmed', 'asset', '', '', 'none', 'no', 'yes'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/chart-of-accounts')
        ->assertInertia(fn ($page) => $page->has('import'));

    // Second visit, having confirmed nothing.
    $this->actingAs($this->actor)
        ->get('/admin/chart-of-accounts')
        ->assertInertia(fn ($page) => $page->where('import', null));
});

it('shows it again when the confirm was refused', function () {
    // The one case the preview must survive a redirect: the rows are wrong and
    // the operator needs to see which.
    previewChart([
        ['9002', '', 'asset', '', '', 'none', 'no', 'yes'],
    ]);

    confirmChart()->assertSessionHasErrors('file');

    $this->actingAs($this->actor)
        ->get('/admin/chart-of-accounts')
        ->assertInertia(fn ($page) => $page->has('import'));
});

/* ── The round trip ──────────────────────────────────────────────────── */

it('reports every row unchanged when nothing was edited', function () {
    // A chart that drifts on a no-op round trip is one nobody can use for
    // bulk corrections.
    $cash = accountByCode('1100');

    previewChart([
        [$cash->code, $cash->name, $cash->type, (string) $cash->subtype, '', $cash->cash_flow_category, $cash->is_cash_equivalent ? 'yes' : 'no', 'yes'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/chart-of-accounts')
        ->assertInertia(fn ($page) => $page
            ->where('import.summary.unchanged_count', 1)
            ->where('import.summary.update_count', 0));
});

it('creates an account from a code it has not seen', function () {
    previewChart([
        ['5999', 'Sundry Expense', 'expense', 'operating_expense', '', 'operating', 'no', 'yes', 'Catch-all'],
    ]);

    confirmChart()->assertSessionHas('success');

    $created = accountByCode('5999');

    expect($created->name)->toBe('Sundry Expense')
        // Derived, never read from the sheet.
        ->and($created->normal_balance)->toBe(ChartOfAccount::BALANCE_DEBIT)
        ->and($created->is_active)->toBeTrue();
});

it('accepts Revenue, which is what the screen calls income', function () {
    previewChart([
        ['4999', 'Other Fees', 'Revenue', '', '', 'operating', 'no', 'yes'],
    ]);

    confirmChart();

    expect(accountByCode('4999')->type)->toBe(ChartOfAccount::TYPE_INCOME)
        ->and(accountByCode('4999')->normal_balance)
        ->toBe(ChartOfAccount::BALANCE_CREDIT);
});

it('nests an account under a parent the same file creates', function () {
    // A chart is typed in code order, so a sub-account usually names a parent
    // that appears above it — but not always, and reordering the file is not
    // something a person should have to do.
    previewChart([
        ['1155', 'Payroll Account', 'asset', 'current_asset', '1150', 'operating', 'yes', 'yes'],
        ['1150', 'Bank Accounts', 'asset', 'current_asset', '', 'operating', 'no', 'yes'],
    ]);

    confirmChart()->assertSessionHas('success');

    expect(accountByCode('1155')->parent_id)
        ->toBe(accountByCode('1150')->getKey());
});

/* ── What the sheet may not do ───────────────────────────────────────── */

it('never takes the normal balance from the sheet', function () {
    // The column exists on the export because an accountant wants to read it.
    // Honouring it would let a sheet mark an expense credit-normal and invert
    // every figure the account reports.
    previewChart([
        ['5998', 'Backwards Expense', 'expense', '', '', 'operating', 'no', 'yes', '', 'credit'],
    ]);

    confirmChart();

    expect(accountByCode('5998')->normal_balance)
        ->toBe(ChartOfAccount::BALANCE_DEBIT);
});

it('never moves a system code or unlocks a locked account', function () {
    $ar = accountByCode('1200');

    expect($ar->is_locked)->toBeTrue();

    previewChart([
        // Same code and type, so the row itself is legal — but it tries to
        // take the sentinel away and clear the lock.
        [$ar->code, 'Renamed Receivable', $ar->type, '', '', 'operating', 'no', 'yes', '', 'credit', '', 'no'],
    ]);

    confirmChart()->assertSessionHas('success');

    $fresh = accountByCode('1200');

    expect($fresh->name)->toBe('Renamed Receivable')
        ->and($fresh->system_code)->toBe($ar->system_code)
        ->and($fresh->is_locked)->toBeTrue();
});

it('refuses to retype a locked account', function () {
    // Its type decides how every figure already posted to it is signed.
    $ar = accountByCode('1200');

    previewChart([
        [$ar->code, $ar->name, 'expense', '', '', 'operating', 'no', 'yes'],
    ]);

    confirmChart()->assertSessionHasErrors('file');

    expect(accountByCode('1200')->type)->toBe(ChartOfAccount::TYPE_ASSET);
});

/* ── Refusals ────────────────────────────────────────────────────────── */

it('refuses a cash equivalent that is not an asset', function () {
    previewChart([
        ['5997', 'Not Cash', 'expense', '', '', 'operating', 'yes', 'yes'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/chart-of-accounts')
        ->assertInertia(fn ($page) => $page->where('import.summary.error_count', 1));
});

it('refuses a parent that would close a loop', function () {
    // A cycle renders as accounts that silently vanish from the tree, and a
    // bulk import is exactly where one gets introduced.
    previewChart([
        ['7001', 'Ring A', 'asset', '', '7002', 'none', 'no', 'yes'],
        ['7002', 'Ring B', 'asset', '', '7001', 'none', 'no', 'yes'],
    ]);

    // BOTH rows are flagged, not just the one that closes it. Each is
    // genuinely in the ring, and naming one end would leave a reader hunting
    // the other.
    $this->actingAs($this->actor)
        ->get('/admin/chart-of-accounts')
        ->assertInertia(fn ($page) => $page
            ->where('import.summary.error_count', 2)
            ->where(
                'import.parsed.0.errors.0',
                'Parent 7002 closes a loop — an account cannot end up beneath itself.',
            ));
});

it('refuses an account that is its own parent', function () {
    previewChart([
        ['7003', 'Self Parent', 'asset', '', '7003', 'none', 'no', 'yes'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/chart-of-accounts')
        ->assertInertia(fn ($page) => $page
            ->where('import.parsed.0.errors.0', 'An account cannot be its own parent.'));
});

it('reports each kind of bad row rather than throwing', function () {
    previewChart([
        ['', 'No Code', 'asset', '', '', 'none', 'no', 'yes'],
        ['7010', '', 'asset', '', '', 'none', 'no', 'yes'],
        ['7011', 'Bad Type', 'liquid', '', '', 'none', 'no', 'yes'],
        ['7012', 'Bad Cash Flow', 'asset', '', '', 'sideways', 'no', 'yes'],
        ['7013', 'Bad Flag', 'asset', '', '', 'none', 'perhaps', 'yes'],
        ['7014', 'No Such Parent', 'asset', '', '9998', 'none', 'no', 'yes'],
        ['7015', 'Twin', 'asset', '', '', 'none', 'no', 'yes'],
        ['7015', 'Twin Again', 'asset', '', '', 'none', 'no', 'yes'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/chart-of-accounts')
        ->assertInertia(fn ($page) => $page->where('import.summary.error_count', 7));
});

it('applies nothing while any row is wrong', function () {
    $before = ChartOfAccount::query()->count();

    previewChart([
        ['7020', 'Perfectly Fine', 'asset', '', '', 'none', 'no', 'yes'],
        ['7021', '', 'asset', '', '', 'none', 'no', 'yes'],
    ]);

    confirmChart()->assertSessionHasErrors('file');

    expect(ChartOfAccount::query()->count())->toBe($before);
});

it('refuses a stale preview token', function () {
    previewChart([
        ['7030', 'Never Applied', 'asset', '', '', 'none', 'no', 'yes'],
    ]);

    $this->actingAs($this->actor)
        ->post('/admin/chart-of-accounts/import/confirm/not-the-token')
        ->assertSessionHasErrors('token');

    expect(ChartOfAccount::query()->where('code', '7030')->exists())->toBeFalse();
});
