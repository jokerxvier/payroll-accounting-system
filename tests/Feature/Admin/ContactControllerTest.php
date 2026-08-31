<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\ContactStudent;
use App\Models\Pas\Invoice;
use App\Models\Pas\Payment;
use App\Models\Pas\School;
use App\Models\User;

/*
 * /admin/contacts (Phase 5 Slice 4).
 *
 * Pinned:
 *  - role gates match App\Policies\Pas\AccountingRoles
 *  - a contact must be a customer, a supplier, or both
 *  - TIN is normalised to digits and unique per school when present
 *  - control-account overrides must be on the right side of the ledger, and
 *    must belong to this school
 *  - search and pagination
 *  - per-row `can`, including for a platform admin
 */

beforeEach(function (): void {
    Contact::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->receivable = ChartOfAccount::factory()->asset()->create(['code' => '1200', 'name' => 'Accounts Receivable']);
    $this->payable = ChartOfAccount::factory()->liability()->create(['code' => '2100', 'name' => 'Accounts Payable']);
    $this->expense = ChartOfAccount::factory()->expense()->create(['code' => '5100', 'name' => 'Salaries']);
});

function contactAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/** @return array<string, mixed> */
function validContactPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'ACME',
        'name' => 'Acme Trading',
        'is_customer' => true,
        'is_supplier' => false,
        'tin' => null,
        'email' => null,
        'phone' => null,
        'address' => null,
        'receivable_account_id' => null,
        'payable_account_id' => null,
        'is_active' => true,
        'notes' => null,
    ], $overrides);
}

/* ── Access ─────────────────────────────────────────────────────────── */

it('lets every accounting role read the register', function (string $role) {
    Contact::factory()->create(['code' => 'C1']);

    $this->actingAs(contactAuthAs($role))
        ->get('/admin/contacts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/contacts/index', false)
            ->has('contacts.data', 1));
})->with(['super-admin', 'accountant', 'payroll-officer', 'auditor']);

it('lets an auditor read but not write', function () {
    $auditor = contactAuthAs('auditor');

    $this->actingAs($auditor)->get('/admin/contacts')->assertOk();
    $this->actingAs($auditor)
        ->post('/admin/contacts', validContactPayload())
        ->assertForbidden();
});

it('locks an ordinary employee out', function () {
    $this->actingAs(contactAuthAs('employee'))
        ->get('/admin/contacts')
        ->assertForbidden();
});

it('requires authentication', function () {
    $this->get('/admin/contacts')->assertRedirect('/login');
});

/* ── The customer/supplier rule ─────────────────────────────────────── */

it('creates a customer', function () {
    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/contacts');

    $contact = Contact::query()->where('code', 'ACME')->firstOrFail();

    expect($contact->is_customer)->toBeTrue()
        ->and($contact->is_supplier)->toBeFalse()
        ->and($contact->is_active)->toBeTrue();
});

it('creates a contact that is both customer and supplier', function () {
    // Common enough that splitting customers and suppliers into two tables
    // would mean maintaining the same entity twice.
    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload([
            'is_customer' => true,
            'is_supplier' => true,
        ]))
        ->assertSessionHasNoErrors();

    $contact = Contact::query()->where('code', 'ACME')->firstOrFail();

    expect($contact->is_customer)->toBeTrue()
        ->and($contact->is_supplier)->toBeTrue();
});

it('refuses a contact that is neither customer nor supplier', function () {
    // It could not appear on any document, so it is not a contact.
    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload([
            'is_customer' => false,
            'is_supplier' => false,
        ]))
        ->assertSessionHasErrors('is_customer');

    expect(Contact::query()->count())->toBe(0);
});

/* ── TIN ────────────────────────────────────────────────────────────── */

it('normalises a punctuated TIN to digits', function () {
    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload(['tin' => '123-456-789-000']))
        ->assertSessionHasNoErrors();

    expect(Contact::query()->where('code', 'ACME')->value('tin'))->toBe('123456789000');
});

it('treats a punctuated and an unpunctuated TIN as the same number', function () {
    // Storing both would defeat the unique index entirely.
    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload(['tin' => '123456789']));

    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload([
            'code' => 'OTHER',
            'tin' => '123-456-789',
        ]))
        ->assertSessionHasErrors('tin');

    expect(Contact::query()->count())->toBe(1);
});

it('allows any number of contacts without a TIN', function () {
    // NULLs are distinct in a unique index, which is what makes the
    // constraint usable at all — most contacts have no TIN on file.
    $actor = contactAuthAs('accountant');

    $this->actingAs($actor)->post('/admin/contacts', validContactPayload(['code' => 'A']));
    $this->actingAs($actor)->post('/admin/contacts', validContactPayload(['code' => 'B']));
    $this->actingAs($actor)->post('/admin/contacts', validContactPayload(['code' => 'C']));

    expect(Contact::query()->count())->toBe(3);
});

it('rejects a TIN that is not 9 to 12 digits', function (string $tin) {
    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload(['tin' => $tin]))
        ->assertSessionHasErrors('tin');
})->with(['12345', '1234567890123']);

it('rejects a duplicate code within the school', function () {
    Contact::factory()->create(['code' => 'ACME']);

    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload())
        ->assertSessionHasErrors('code');
});

/* ── Control-account overrides ──────────────────────────────────────── */

it('accepts control accounts on the correct side of the ledger', function () {
    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload([
            'is_supplier' => true,
            'receivable_account_id' => $this->receivable->id,
            'payable_account_id' => $this->payable->id,
        ]))
        ->assertSessionHasNoErrors();

    $contact = Contact::query()->where('code', 'ACME')->firstOrFail();

    expect($contact->receivable_account_id)->toBe($this->receivable->id)
        ->and($contact->payable_account_id)->toBe($this->payable->id);
});

it('refuses a receivable pointed at something that is not an asset', function () {
    // Money owed to the school is an asset. An expense here would silently
    // corrupt every report that reads it.
    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload([
            'receivable_account_id' => $this->expense->id,
        ]))
        ->assertSessionHasErrors('receivable_account_id');
});

it('refuses a payable pointed at something that is not a liability', function () {
    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload([
            'is_supplier' => true,
            'payable_account_id' => $this->receivable->id,
        ]))
        ->assertSessionHasErrors('payable_account_id');
});

it('refuses a control account belonging to another school', function () {
    $other = School::factory()->create(['slug' => 'contact-foreign-account']);
    $foreign = ChartOfAccount::query()->withoutGlobalScopes()->create([
        'school_id' => $other->getKey(),
        'code' => '1299',
        'name' => 'Foreign AR',
        'type' => ChartOfAccount::TYPE_ASSET,
        'normal_balance' => ChartOfAccount::BALANCE_DEBIT,
        'cash_flow_category' => ChartOfAccount::CASH_FLOW_OPERATING,
        'is_active' => true,
        'is_locked' => false,
    ]);

    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload([
            'receivable_account_id' => $foreign->getKey(),
        ]))
        ->assertSessionHasErrors('receivable_account_id');
});

it('leaves the overrides null so posting falls back to the system accounts', function () {
    // Null means "use this school's AR_CONTROL / AP_CONTROL", which is how
    // Slice 5 resolves it. Only a deliberate departure is stored.
    $this->actingAs(contactAuthAs('accountant'))
        ->post('/admin/contacts', validContactPayload())
        ->assertSessionHasNoErrors();

    $contact = Contact::query()->where('code', 'ACME')->firstOrFail();

    expect($contact->receivable_account_id)->toBeNull()
        ->and($contact->payable_account_id)->toBeNull();
});

/* ── Search and pagination ──────────────────────────────────────────── */

it('searches by name, code, and TIN', function (string $term) {
    Contact::factory()->create(['code' => 'ACME', 'name' => 'Acme Trading', 'tin' => '123456789']);
    Contact::factory()->create(['code' => 'ZED', 'name' => 'Zed Supplies']);

    $this->actingAs(contactAuthAs('accountant'))
        ->get('/admin/contacts?search='.urlencode($term))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.code', 'ACME'));
})->with(['Acme', 'ACME', '123456789']);

it('filters to customers or suppliers', function () {
    Contact::factory()->customer()->create(['code' => 'CUST']);
    Contact::factory()->supplier()->create(['code' => 'SUPP']);

    $this->actingAs(contactAuthAs('accountant'))
        ->get('/admin/contacts?role=supplier')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.code', 'SUPP'));
});

it('paginates', function () {
    Contact::factory()->count(30)->create();

    $this->actingAs(contactAuthAs('accountant'))
        ->get('/admin/contacts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('contacts.data', 25)
            ->where('contacts.last_page', 2)
            ->where('contacts.total', 30));
});

/* ── Per-row permissions ────────────────────────────────────────────── */

it('ships per-row permissions, including for a platform admin', function () {
    Contact::factory()->create(['code' => 'ACME']);

    $platformAdmin = User::factory()->withoutLmsMirror()->create();
    $platformAdmin->syncRoles(['platform-admin']);

    $this->actingAs($platformAdmin->fresh())
        ->get('/admin/contacts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('contacts.data.0.can.update', true)
            ->where('contacts.data.0.can.delete', true));
});

it('narrows each account picker to its own side of the ledger', function () {
    $this->actingAs(contactAuthAs('accountant'))
        ->get('/admin/contacts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('receivableAccountOptions', fn ($opts) => collect($opts)->every(
                fn ($a) => $a['type'] === ChartOfAccount::TYPE_ASSET,
            ))
            ->where('payableAccountOptions', fn ($opts) => collect($opts)->every(
                fn ($a) => $a['type'] === ChartOfAccount::TYPE_LIABILITY,
            )));
});

/* ── Update and delete ──────────────────────────────────────────────── */

it('updates without tripping its own unique rules', function () {
    $contact = Contact::factory()->create(['code' => 'ACME', 'tin' => '123456789']);

    $this->actingAs(contactAuthAs('accountant'))
        ->patch("/admin/contacts/{$contact->getKey()}", validContactPayload([
            'code' => 'ACME',
            'tin' => '123456789',
            'name' => 'Acme Trading Corp',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/contacts');

    expect($contact->fresh()->name)->toBe('Acme Trading Corp');
});

it('deletes an unreferenced contact', function () {
    $contact = Contact::factory()->create(['code' => 'ACME']);

    $this->actingAs(contactAuthAs('accountant'))
        ->delete("/admin/contacts/{$contact->getKey()}")
        ->assertRedirect('/admin/contacts')
        ->assertSessionHas('success');

    expect(Contact::query()->count())->toBe(0);
});

/* ── Deleting a contact that documents point at ─────────────────────── */

it('refuses to delete a contact that has been invoiced', function () {
    $contact = Contact::factory()->customer()->create();

    Invoice::factory()->create([
        'contact_id' => $contact->getKey(),
        'type' => Invoice::TYPE_SALES,
    ]);

    // This used to reach `restrictOnDelete` as an unhandled 500: the guard
    // existed and was worded, but `isReferenced()` was a stub returning false
    // from Slice 4 until Slice 11.
    $this->actingAs(contactAuthAs('accountant'))
        ->delete(route('admin.contacts.destroy', $contact))
        ->assertRedirect(route('admin.contacts.index'));

    expect(Contact::query()->find($contact->getKey()))->not->toBeNull();
});

it('refuses to delete a contact that has taken a payment', function () {
    $contact = Contact::factory()->customer()->create();

    Payment::factory()->receipt()->create([
        'contact_id' => $contact->getKey(),
    ]);

    $this->actingAs(contactAuthAs('accountant'))
        ->delete(route('admin.contacts.destroy', $contact));

    expect(Contact::query()->find($contact->getKey()))->not->toBeNull();
});

it('does not offer the delete button for a contact that cannot go', function () {
    $contact = Contact::factory()->customer()->create();
    Invoice::factory()->create(['contact_id' => $contact->getKey()]);

    // A visible control that 500s on click is worse than no control.
    $this->actingAs(contactAuthAs('accountant'))
        ->get(route('admin.contacts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('contacts.data.0.can.delete', false));
});

it('still deletes a contact nothing references', function () {
    $contact = Contact::factory()->customer()->create();

    $this->actingAs(contactAuthAs('accountant'))
        ->delete(route('admin.contacts.destroy', $contact));

    expect(Contact::query()->find($contact->getKey()))->toBeNull();
});

it('deletes a contact whose only reference is a student link', function () {
    $contact = Contact::factory()->customer()->create();

    ContactStudent::create([
        'contact_id' => $contact->getKey(),
        'lms_student_id' => 77,
        'student_name' => 'Francesca Inez',
        'is_primary_payer' => true,
    ]);

    // The link cascades: it has no meaning without the payer, and it is not
    // financial history worth preserving on its own.
    $this->actingAs(contactAuthAs('accountant'))
        ->delete(route('admin.contacts.destroy', $contact));

    expect(Contact::query()->find($contact->getKey()))->toBeNull()
        ->and(ContactStudent::query()->count())->toBe(0);
});
