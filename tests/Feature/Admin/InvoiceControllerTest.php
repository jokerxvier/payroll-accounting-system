<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\DocumentNumberSeries;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use App\Models\User;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * /admin/invoices (Phase 5 Slice 5).
 *
 * Pinned:
 *  - role gates match App\Policies\Pas\AccountingRoles
 *  - only a draft is editable, including for a platform admin
 *  - the server recomputes totals; a client-supplied figure is never trusted
 *  - approval numbers, posts, and flips the status together
 *  - validation: the counterparty must be the right kind, quantities and
 *    dates must make sense, and cross-tenant references are refused
 *  - the printed face renders for a draft and for an issued document
 */

beforeEach(function (): void {
    InvoiceLine::query()->withoutGlobalScopes()->delete();
    Invoice::query()->withoutGlobalScopes()->delete();
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    DocumentNumberSeries::query()->withoutGlobalScopes()->delete();
    Contact::query()->withoutGlobalScopes()->delete();
    TaxRate::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    DocumentNumberSeries::factory()->create(['next_number' => 1]);

    $this->customer = Contact::factory()->create(['name' => 'Dela Cruz Family']);
    $this->income = ChartOfAccount::query()->where('code', '4100')->firstOrFail();
    $this->vat = TaxRate::query()->where('code', 'VAT_12_SALES')->firstOrFail();
    $this->exempt = TaxRate::query()->where('code', 'VAT_EXEMPT')->firstOrFail();
});

function invoiceAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/** @return array<string, mixed> */
function invoicePayload(array $overrides = [], array $lineOverrides = []): array
{
    return array_merge([
        'type' => Invoice::TYPE_SALES,
        'contact_id' => test()->customer->id,
        'reference' => null,
        'issue_date' => '2026-08-15',
        'due_date' => '2026-09-15',
        'is_vat_inclusive' => false,
        'notes' => null,
        'terms' => null,
        'lines' => [array_merge([
            'description' => 'Tuition — August 2026',
            'quantity' => '1',
            'unit_price_centavos' => 1_000_000,
            'account_id' => test()->income->id,
            'tax_rate_id' => test()->vat->id,
        ], $lineOverrides)],
    ], $overrides);
}

/** A persisted draft, created through the endpoint so the server computes it. */
function storedDraft(array $overrides = [], array $lineOverrides = []): Invoice
{
    test()->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), invoicePayload($overrides, $lineOverrides))
        ->assertSessionHasNoErrors();

    return Invoice::query()->latest('id')->firstOrFail();
}

/* ── Access ─────────────────────────────────────────────────────────── */

it('lets every accounting-view role see the list', function (string $role) {
    $this->actingAs(invoiceAuthAs($role))
        ->get(route('admin.invoices.index'))
        ->assertOk();
})->with(['super-admin', 'accountant', 'payroll-officer', 'auditor']);

it('refuses roles outside the accounting set', function (string $role) {
    $this->actingAs(invoiceAuthAs($role))
        ->get(route('admin.invoices.index'))
        ->assertForbidden();
})->with(['hr', 'employee']);

it('lets an auditor read but not draft', function () {
    $auditor = invoiceAuthAs('auditor');

    $this->actingAs($auditor)->get(route('admin.invoices.index'))->assertOk();
    $this->actingAs($auditor)->get(route('admin.invoices.create'))->assertForbidden();
    $this->actingAs($auditor)
        ->post(route('admin.invoices.store'), invoicePayload())
        ->assertForbidden();
});

it('lets a payroll officer draft but not approve', function () {
    // Maker-checker: MANAGE drafts, POST_LEDGER commits. Same split the
    // journal uses, because approving is what puts the document in the books.
    $officer = invoiceAuthAs('payroll-officer');
    $invoice = storedDraft();

    $this->actingAs($officer)->get(route('admin.invoices.edit', $invoice))->assertOk();
    $this->actingAs($officer)
        ->post(route('admin.invoices.approve', $invoice))
        ->assertForbidden();
});

/* ── Drafting ───────────────────────────────────────────────────────── */

it('stores a draft with no number and computes its totals', function () {
    $invoice = storedDraft();

    expect($invoice->status)->toBe(Invoice::STATUS_DRAFT)
        // No serial burned by a draft that may never be approved.
        ->and($invoice->number)->toBeNull()
        ->and($invoice->vatable_sales_centavos)->toBe(1_000_000)
        ->and($invoice->vat_centavos)->toBe(120_000)
        ->and($invoice->total_centavos)->toBe(1_120_000)
        ->and($invoice->lines()->count())->toBe(1);
});

it('computes the totals server-side even when the client sends its own', function () {
    // The client shows a running preview; it is never authoritative. A
    // payload carrying its own totals must not be able to set them.
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), invoicePayload([
            'total_centavos' => 1,
            'vat_centavos' => 1,
            'vatable_sales_centavos' => 1,
        ]))
        ->assertSessionHasNoErrors();

    $invoice = Invoice::query()->latest('id')->firstOrFail();

    expect($invoice->total_centavos)->toBe(1_120_000)
        ->and($invoice->vat_centavos)->toBe(120_000);
});

it('recomputes totals when a draft is edited', function () {
    $invoice = storedDraft();

    $this->actingAs(invoiceAuthAs('accountant'))
        ->put(route('admin.invoices.update', $invoice), invoicePayload([], [
            'unit_price_centavos' => 2_000_000,
        ]))
        ->assertSessionHasNoErrors();

    expect($invoice->refresh()->total_centavos)->toBe(2_240_000);
});

it('replaces lines wholesale rather than accumulating them', function () {
    $invoice = storedDraft();

    $payload = invoicePayload();
    $payload['lines'][] = [
        'description' => 'Books',
        'quantity' => '2',
        'unit_price_centavos' => 50_000,
        'account_id' => $this->income->id,
        'tax_rate_id' => $this->exempt->id,
    ];

    $this->actingAs(invoiceAuthAs('accountant'))
        ->put(route('admin.invoices.update', $invoice), $payload)
        ->assertSessionHasNoErrors();

    expect($invoice->refresh()->lines()->count())->toBe(2)
        ->and($invoice->vat_exempt_sales_centavos)->toBe(100_000)
        ->and($invoice->total_centavos)->toBe(1_220_000);
});

it('deletes a draft and its lines together', function () {
    $invoice = storedDraft();

    $this->actingAs(invoiceAuthAs('accountant'))
        ->delete(route('admin.invoices.destroy', $invoice))
        ->assertRedirect();

    expect(Invoice::query()->count())->toBe(0)
        // The observer removes them through Eloquent so each writes an audit
        // row, rather than letting the FK cascade take them silently.
        ->and(InvoiceLine::query()->count())->toBe(0);
});

/* ── Validation ─────────────────────────────────────────────────────── */

it('refuses a sales invoice against a contact who is not a customer', function () {
    $supplier = Contact::factory()->supplier()->create(['name' => 'Paper Supplier']);

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), invoicePayload(['contact_id' => $supplier->id]))
        ->assertSessionHasErrors('contact_id');
});

it('refuses an invoice with no lines', function () {
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), invoicePayload(['lines' => []]))
        ->assertSessionHasErrors('lines');
});

it('refuses a zero quantity', function () {
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), invoicePayload([], ['quantity' => '0']))
        ->assertSessionHasErrors('lines.0.quantity');
});

it('refuses a due date before the issue date', function () {
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), invoicePayload(['due_date' => '2026-08-01']))
        ->assertSessionHasErrors('due_date');
});

it('refuses an account belonging to another school', function () {
    $otherSchool = School::factory()->create();
    $foreign = ChartOfAccount::factory()->create(['school_id' => $otherSchool->id, 'code' => '4999']);

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), invoicePayload([], ['account_id' => $foreign->id]))
        ->assertSessionHasErrors('lines.0.account_id');
});

/* ── Immutability once issued ───────────────────────────────────────── */

it('refuses to edit an issued document', function () {
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.approve', $invoice))
        ->assertRedirect();

    $accountant = invoiceAuthAs('accountant');

    $this->actingAs($accountant)
        ->get(route('admin.invoices.edit', $invoice->refresh()))
        ->assertForbidden();
    $this->actingAs($accountant)
        ->put(route('admin.invoices.update', $invoice), invoicePayload())
        ->assertForbidden();
    $this->actingAs($accountant)
        ->delete(route('admin.invoices.destroy', $invoice))
        ->assertForbidden();
});

it('refuses a platform admin the same edits, despite Gate::before', function () {
    // The regression this guard exists for. Gate::before grants a platform
    // admin every ability, so the policy's state predicate is short-
    // circuited entirely — the refusal has to live outside authorization.
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.approve', $invoice));

    $platformAdmin = User::factory()->create(['lms_user_id' => null]);
    $platformAdmin->syncRoles(['platform-admin']);

    $this->actingAs($platformAdmin)
        ->put(route('admin.invoices.update', $invoice->refresh()), invoicePayload())
        ->assertForbidden();
    $this->actingAs($platformAdmin)
        ->delete(route('admin.invoices.destroy', $invoice))
        ->assertForbidden();
});

it('offers no edit or delete action on an issued document', function () {
    // The list must not advertise a transition the server would refuse.
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.approve', $invoice));

    $this->actingAs(invoiceAuthAs('accountant'))
        ->get(route('admin.invoices.index'))
        ->assertInertia(fn ($page) => $page
            ->where('invoices.data.0.can.update', false)
            ->where('invoices.data.0.can.delete', false)
            ->where('invoices.data.0.can.void', true));
});

/* ── Approval ───────────────────────────────────────────────────────── */

it('numbers, posts, and approves in one request', function () {
    $invoice = storedDraft();

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.approve', $invoice))
        ->assertRedirect()
        ->assertSessionHas('success');

    $invoice->refresh();

    expect($invoice->status)->toBe(Invoice::STATUS_APPROVED)
        ->and($invoice->number)->toBe('SI-000001')
        ->and($invoice->journal_entry_id)->not->toBeNull();
});

it('reports a missing numbering series as guidance rather than a 500', function () {
    DocumentNumberSeries::query()->withoutGlobalScopes()->delete();
    $invoice = storedDraft();

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.approve', $invoice))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_DRAFT);
});

it('reports a closed period as guidance rather than a 500', function () {
    $invoice = storedDraft();
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.approve', $invoice))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_DRAFT)
        ->and($invoice->number)->toBeNull();
});

/* ── Voiding ────────────────────────────────────────────────────────── */

it('voids an issued document, keeps its number, and reverses the ledger', function () {
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))->post(route('admin.invoices.approve', $invoice));

    $number = $invoice->refresh()->number;

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.void', $invoice), ['reason' => 'Billed in error'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $invoice->refresh();

    expect($invoice->status)->toBe(Invoice::STATUS_VOIDED)
        // The serial stays accounted for — a missing number reads as a
        // document issued and hidden.
        ->and($invoice->number)->toBe($number)
        ->and($invoice->void_reason)->toBe('Billed in error')
        // Original plus reversal, both posted, offsetting to zero.
        ->and(JournalEntry::query()->count())->toBe(2)
        ->and(JournalEntry::query()->sum('total_debit_centavos'))->toBe(2_240_000);
});

it('refuses to void a draft', function () {
    $invoice = storedDraft();

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.void', $invoice))
        ->assertForbidden();
});

/* ── The printed face ───────────────────────────────────────────────── */

it('prints an issued document as a PDF', function () {
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))->post(route('admin.invoices.approve', $invoice));

    $response = $this->actingAs(invoiceAuthAs('accountant'))
        ->get(route('admin.invoices.print', $invoice->refresh()));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('prints a draft too, marked as not issued', function () {
    // Sending a proforma before the document is numbered is ordinary;
    // refusing would push people into screenshotting the screen.
    $invoice = storedDraft();

    $this->actingAs(invoiceAuthAs('accountant'))
        ->get(route('admin.invoices.print', $invoice))
        ->assertOk();
});

it('lets an auditor print', function () {
    $invoice = storedDraft();

    $this->actingAs(invoiceAuthAs('auditor'))
        ->get(route('admin.invoices.print', $invoice))
        ->assertOk();
});
