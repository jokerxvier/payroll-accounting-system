<?php

declare(strict_types=1);

use App\Actions\Accounting\PostOpeningBalances;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/*
 * The open-items worksheet, from upload to recorded documents.
 *
 * The action's own refusals are proved in `OpeningItemTest`. What this file
 * covers is the part a person touches: that a bad row is reported rather than
 * thrown, that the whole file is refused while any row is bad, and that the
 * reconciliation figure is on the preview BEFORE anyone commits — which is
 * the only moment it can still change a decision.
 */

beforeEach(function (): void {
    InvoiceLine::query()->withoutGlobalScopes()->delete();
    Invoice::query()->withoutGlobalScopes()->delete();
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    Contact::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-06',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $this->payer = Contact::factory()->create([
        'name' => 'Dela Cruz Family',
        'is_customer' => true,
    ]);

    $this->actor = User::factory()->create();
    $this->actor->syncRoles(['accountant']);
});

/**
 * A CSV in the template's shape, uploaded for real so the heading slugging
 * maatwebsite applies is exercised rather than assumed.
 *
 * @param  list<array<int, string>>  $rows  type, contact, number, issue, due, total, paid
 */
function openItemCsv(array $rows): UploadedFile
{
    $csv = "type,contact_name,document_number,issue_date,due_date,total_amount,amount_already_paid,student_name\n";

    foreach ($rows as $row) {
        // Padded to the full width so a row that omits the trailing optional
        // columns still lines up with the heading row.
        $csv .= implode(',', array_pad($row, 8, ''))."\n";
    }

    $path = tempnam(sys_get_temp_dir(), 'oi').'.csv';
    file_put_contents($path, $csv);

    return new UploadedFile($path, 'opening-items.csv', 'text/csv', null, true);
}

function openBooks(int $receivableCentavos): void
{
    $ar = ChartOfAccount::query()
        ->where('system_code', ChartOfAccount::SYSTEM_AR_CONTROL)
        ->firstOrFail();

    app(PostOpeningBalances::class)->execute(
        CarbonImmutable::parse('2026-06-30'),
        [[
            'account_id' => (int) $ar->getKey(),
            'debit_centavos' => $receivableCentavos,
            'credit_centavos' => 0,
        ]],
        (int) test()->actor->getKey(),
        plugToRetainedEarnings: true,
    );
}

/** @param list<array<int, string>> $rows */
function previewItems(array $rows): TestResponse
{
    return test()->actingAs(test()->actor)->post('/admin/opening-items/preview', [
        'file' => openItemCsv($rows),
    ]);
}

/* ── Access ──────────────────────────────────────────────────────────── */

it('is closed to a role that cannot open the books', function () {
    $clerk = User::factory()->create();
    $clerk->syncRoles(['payroll-officer']);

    $this->actingAs($clerk)->get('/admin/opening-items')->assertForbidden();
});

/* ── Preview ─────────────────────────────────────────────────────────── */

it('parses a good file and reports what it will record', function () {
    openBooks(500_000);

    previewItems([
        ['sales', 'Dela Cruz Family', 'OLD-0042', '2026-05-31', '2026-06-15', '3000.00', '0'],
        ['sales', 'Dela Cruz Family', 'OLD-0043', '2026-06-10', '2026-06-25', '2000.00', '0'],
    ])->assertRedirect('/admin/opening-items');

    $this->actingAs($this->actor)
        ->get('/admin/opening-items')
        ->assertInertia(fn ($page) => $page
            ->where('summary.row_count', 2)
            ->where('summary.error_count', 0)
            ->where('summary.total_centavos', 500_000)
            ->where('summary.outstanding_centavos', 500_000)
            ->where('reconciliation.0.control_centavos', 500_000)
            ->where('reconciliation.0.items_centavos', 500_000)
            ->where('reconciliation.0.is_reconciled', true));
});

it('states the difference on the preview, before anything is recorded', function () {
    // The only moment the figure can still change a decision.
    openBooks(500_000);

    previewItems([
        ['sales', 'Dela Cruz Family', 'OLD-0042', '2026-05-31', '2026-06-15', '3200.00', '0'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/opening-items')
        ->assertInertia(fn ($page) => $page
            ->where('reconciliation.0.difference_centavos', 180_000)
            ->where('reconciliation.0.is_reconciled', false));

    expect(Invoice::query()->openingItems()->count())->toBe(0);
});

it('reports a bad row rather than throwing', function () {
    openBooks(500_000);

    previewItems([
        ['sales', 'Nobody At All', 'OLD-1', '2026-05-31', '2026-06-15', '1000.00', '0'],
        ['delivery', 'Dela Cruz Family', 'OLD-2', '2026-05-31', '2026-06-15', '1000.00', '0'],
        ['sales', 'Dela Cruz Family', 'OLD-3', '2026-05-31', '2026-06-15', '1000.00', '2000.00'],
        ['sales', 'Dela Cruz Family', 'OLD-4', '2026-07-15', '2026-07-30', '1000.00', '0'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/opening-items')
        ->assertInertia(fn ($page) => $page
            ->where('summary.row_count', 4)
            // Unknown contact, bad type, overpaid, dated after cutover.
            ->where('summary.error_count', 4));
});

it('warns without refusing when a serial matches this system\'s own numbering', function () {
    // Correct to import — reissuing it later would be worse — but it silently
    // moves the live counter, and that is worth knowing beforehand.
    openBooks(100_000);

    previewItems([
        ['sales', 'Dela Cruz Family', 'INV-2025-00042', '2026-05-31', '2026-06-15', '1000.00', '0'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/opening-items')
        ->assertInertia(fn ($page) => $page
            ->where('summary.error_count', 0)
            ->where('summary.warning_count', 1));
});

/* ── Confirm ─────────────────────────────────────────────────────────── */

it('records the documents once confirmed', function () {
    openBooks(500_000);

    previewItems([
        ['sales', 'Dela Cruz Family', 'OLD-0042', '2026-05-31', '2026-06-15', '3000.00', '500.00'],
        ['sales', 'Dela Cruz Family', 'OLD-0043', '2026-06-10', '', '2000.00', '0'],
    ]);

    $token = session('opening_item_import.token');

    $this->actingAs($this->actor)
        ->post("/admin/opening-items/confirm/{$token}")
        ->assertRedirect('/admin/opening-items')
        ->assertSessionHas('success');

    $items = Invoice::query()->openingItems()->orderBy('number')->get();

    expect($items)->toHaveCount(2)
        ->and($items->pluck('number')->all())->toBe(['OLD-0042', 'OLD-0043'])
        ->and($items->every(fn (Invoice $i): bool => $i->journal_entry_id === null))->toBeTrue()
        // Recorded at the brought-forward balance: ₱3,000 issued less the
        // ₱500 collected before the move.
        ->and($items->first()->total_centavos)->toBe(250_000)
        ->and($items->first()->status)->toBe(Invoice::STATUS_SENT)
        // A blank due date is allowed: nobody agreed a deadline.
        ->and($items->last()->due_date)->toBeNull();
});

it('refuses the whole file while any row is bad', function () {
    // A partial sub-ledger reconciles to nothing, and the difference it
    // reports would send somebody hunting a discrepancy that is only the
    // rows that failed.
    openBooks(500_000);

    previewItems([
        ['sales', 'Dela Cruz Family', 'OLD-0042', '2026-05-31', '2026-06-15', '3000.00', '0'],
        ['sales', 'Nobody At All', 'OLD-0043', '2026-05-31', '2026-06-15', '2000.00', '0'],
    ]);

    $token = session('opening_item_import.token');

    $this->actingAs($this->actor)
        ->post("/admin/opening-items/confirm/{$token}")
        ->assertSessionHasErrors('file');

    expect(Invoice::query()->openingItems()->count())->toBe(0);
});

it('refuses a stale preview token', function () {
    openBooks(500_000);

    previewItems([
        ['sales', 'Dela Cruz Family', 'OLD-0042', '2026-05-31', '2026-06-15', '5000.00', '0'],
    ]);

    $this->actingAs($this->actor)
        ->post('/admin/opening-items/confirm/not-the-token')
        ->assertSessionHasErrors('token');

    expect(Invoice::query()->openingItems()->count())->toBe(0);
});

it('surfaces the action\'s refusal instead of a stack trace', function () {
    // Books never opened: the action throws, and the page has to say so.
    previewItems([
        ['sales', 'Dela Cruz Family', 'OLD-0042', '2026-05-31', '2026-06-15', '5000.00', '0'],
    ]);

    $token = session('opening_item_import.token');

    $this->actingAs($this->actor)
        ->post("/admin/opening-items/confirm/{$token}")
        ->assertSessionHasErrors('file');
});
