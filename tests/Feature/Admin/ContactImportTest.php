<?php

declare(strict_types=1);

use App\Exports\ContactExport;
use App\Exports\ContactTemplateExport;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\User;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/*
 * The contact register's spreadsheet round trip.
 *
 * The property that makes it trustworthy is not that it imports — it is that
 * exporting and re-importing without touching the file changes nothing. A
 * register that drifts on a no-op round trip cannot be used for the job people
 * actually want it for: take it away, fix twelve rows, put it back.
 *
 * After that, the things worth pinning are the ones a person would be caught
 * out by: `code` is the join key, so editing it splits a contact in two rather
 * than renaming it; and a file with any bad row applies nothing at all.
 */

beforeEach(function (): void {
    Contact::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->syncRoles(['accountant']);

    $this->payer = Contact::factory()->create([
        'code' => 'C-0001',
        'name' => 'Dela Cruz Family',
        'is_customer' => true,
        'is_supplier' => false,
        'email' => 'old@example.com',
        'tin' => null,
    ]);
});

/**
 * A CSV in the export's shape, uploaded for real so the heading slugging
 * maatwebsite applies is exercised rather than assumed.
 *
 * @param  list<array<int, string>>  $rows
 */
function contactCsv(array $rows): UploadedFile
{
    $csv = 'code (do not change),name,is_customer,is_supplier,tin,email,phone,'
        ."address,receivable_account_code,payable_account_code,is_active,notes\n";

    foreach ($rows as $row) {
        $csv .= implode(',', array_pad($row, 12, ''))."\n";
    }

    $path = tempnam(sys_get_temp_dir(), 'ci').'.csv';
    file_put_contents($path, $csv);

    return new UploadedFile($path, 'contacts.csv', 'text/csv', null, true);
}

/** @param list<array<int, string>> $rows */
function previewContacts(array $rows): TestResponse
{
    return test()->actingAs(test()->actor)->post('/admin/contacts/import/preview', [
        'file' => contactCsv($rows),
    ]);
}

function confirmContacts(): TestResponse
{
    return test()->actingAs(test()->actor)
        ->post('/admin/contacts/import/confirm/'.session('contact_import.token'));
}

/* ── Access ──────────────────────────────────────────────────────────── */

it('lets a read-only role export but not import', function () {
    // The two ends are gated differently on purpose: export answers to
    // `viewAny`, import to `create`. An auditor is the role that separates
    // them — read the register, change nothing.
    $auditor = User::factory()->create();
    $auditor->syncRoles(['auditor']);

    $this->actingAs($auditor)->get('/admin/contacts/export')->assertOk();
    $this->actingAs($auditor)->get('/admin/contacts/import')->assertForbidden();
    $this->actingAs($auditor)
        ->get('/admin/contacts/import/template')
        ->assertForbidden();
});

/* ── Export ──────────────────────────────────────────────────────────── */

it('exports the register as a spreadsheet', function () {
    $this->actingAs($this->actor)
        ->get('/admin/contacts/export')
        ->assertOk()
        ->assertHeader(
            'content-disposition',
            'attachment; filename=contacts.xlsx',
        );
});

it('offers a template with the same columns as the export', function () {
    // Interchangeable on purpose: the template is what you download when
    // there is nothing to export yet.
    $this->actingAs($this->actor)
        ->get('/admin/contacts/import/template')
        ->assertOk();

    expect((new ContactTemplateExport)->headings())
        ->toBe((new ContactExport)->headings());
});

/* ── The round trip ──────────────────────────────────────────────────── */

it('reports every row unchanged when nothing was edited', function () {
    // The load-bearing property. A register that drifts on a no-op round trip
    // is one nobody can use for bulk corrections.
    previewContacts([
        ['C-0001', 'Dela Cruz Family', 'yes', 'no', '', 'old@example.com'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/contacts/import')
        ->assertInertia(fn ($page) => $page
            ->where('summary.unchanged_count', 1)
            ->where('summary.update_count', 0)
            ->where('summary.create_count', 0));
});

it('names the fields that move on an update', function () {
    // "12 contacts will be updated" is not something anyone can check.
    previewContacts([
        ['C-0001', 'Dela Cruz Family', 'yes', 'no', '', 'new@example.com', '0917 000 1234'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/contacts/import')
        ->assertInertia(fn ($page) => $page
            ->where('summary.update_count', 1)
            ->where('parsed.0.changes.email.from', 'old@example.com')
            ->where('parsed.0.changes.email.to', 'new@example.com')
            ->where('parsed.0.changes.phone.to', '0917 000 1234'));
});

it('creates a contact from a code it has not seen', function () {
    previewContacts([
        ['C-0002', 'Santos Family', 'yes', 'no', '', 'santos@example.com'],
    ]);

    confirmContacts()
        ->assertRedirect('/admin/contacts')
        ->assertSessionHas('success');

    $created = Contact::query()->where('code', 'C-0002')->sole();

    expect($created->name)->toBe('Santos Family')
        ->and($created->is_customer)->toBeTrue()
        ->and($created->is_active)->toBeTrue();
});

it('updates the contact a code already belongs to', function () {
    previewContacts([
        ['C-0001', 'Dela Cruz Household', 'yes', 'yes', '', 'new@example.com'],
    ]);

    confirmContacts()->assertSessionHas('success');

    $contact = $this->payer->fresh();

    expect($contact->name)->toBe('Dela Cruz Household')
        ->and($contact->email)->toBe('new@example.com')
        ->and($contact->is_supplier)->toBeTrue()
        // One contact, not two — the code matched.
        ->and(Contact::query()->count())->toBe(1);
});

it('leaves an untouched row alone rather than resaving it', function () {
    // Resaving would bump `updated_at` on a register somebody is about to
    // audit, and make the log claim work that never happened.
    $before = $this->payer->updated_at;

    previewContacts([
        ['C-0001', 'Dela Cruz Family', 'yes', 'no', '', 'old@example.com'],
        ['C-0002', 'Santos Family', 'yes', 'no', '', 'santos@example.com'],
    ]);

    confirmContacts();

    expect($this->payer->fresh()->updated_at->timestamp)
        ->toBe($before->timestamp);
});

/* ── Refusals ────────────────────────────────────────────────────────── */

it('applies nothing while any row is wrong', function () {
    previewContacts([
        ['C-0002', 'Santos Family', 'yes', 'no', '', 'santos@example.com'],
        ['C-0003', '', 'yes', 'no', '', 'nameless@example.com'],
    ]);

    confirmContacts()->assertSessionHasErrors('file');

    // Including the good row: a part-applied register is one an operator
    // cannot describe.
    expect(Contact::query()->count())->toBe(1);
});

it('reports each kind of bad row rather than throwing', function () {
    previewContacts([
        ['C-0002', 'No Roles', 'no', 'no', '', ''],
        ['C-0003', 'Bad Email', 'yes', 'no', '', 'not-an-email'],
        ['C-0004', 'Short TIN', 'yes', 'no', '123', ''],
        ['C-0005', 'No Such Account', 'yes', 'no', '', '', '', '', '9999'],
        ['', 'No Code At All', 'yes', 'no', '', ''],
        ['C-0006', 'Twin', 'yes', 'no', '', ''],
        ['C-0006', 'Twin Again', 'yes', 'no', '', ''],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/contacts/import')
        ->assertInertia(fn ($page) => $page->where('summary.error_count', 6));
});

it('refuses a receivable override that is not an asset account', function () {
    // A receivable pointed at a liability puts every balance that contact
    // owes on the wrong side of the books.
    previewContacts([
        ['C-0002', 'Wrong Side', 'yes', 'no', '', '', '', '', '2100'],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/contacts/import')
        ->assertInertia(fn ($page) => $page
            ->where('summary.error_count', 1)
            // Reads as a sentence. It said "is a a liability account" until
            // a demo file surfaced it — the article has to be computed with
            // the type, not printed beside it.
            ->where(
                'parsed.0.errors.0',
                'Account 2100 is a liability account. A receivable override has to be an asset.',
            ));
});

it('refuses a TIN that already belongs to somebody else', function () {
    $this->payer->update(['tin' => '123456789']);

    previewContacts([
        ['C-0002', 'Impostor', 'yes', 'no', '123-456-789', ''],
    ]);

    $this->actingAs($this->actor)
        ->get('/admin/contacts/import')
        ->assertInertia(fn ($page) => $page->where('summary.error_count', 1));
});

it('accepts a TIN with punctuation, as the form does', function () {
    previewContacts([
        ['C-0002', 'Punctuated', 'yes', 'no', '123-456-789', ''],
    ]);

    confirmContacts();

    expect(Contact::query()->where('code', 'C-0002')->sole()->tin)
        ->toBe('123456789');
});

it('refuses a stale preview token', function () {
    previewContacts([
        ['C-0002', 'Santos Family', 'yes', 'no', '', ''],
    ]);

    $this->actingAs($this->actor)
        ->post('/admin/contacts/import/confirm/not-the-token')
        ->assertSessionHasErrors('token');

    expect(Contact::query()->count())->toBe(1);
});
