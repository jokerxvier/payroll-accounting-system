<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\ContactStudent;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\RecurringInvoice;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use App\Models\User;
use App\Services\Accounting\InvoicePdf;
use Database\Seeders\AccountingCatalogSeeder;
use Spatie\Multitenancy\Models\Tenant;

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
    Contact::query()->withoutGlobalScopes()->delete();
    TaxRate::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

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

it('numbers a draft on creation and computes its totals', function () {
    $invoice = storedDraft();

    expect($invoice->status)->toBe(Invoice::STATUS_DRAFT)
        // Numbered at creation rather than at approval: the number is an
        // internal reference now, not a BIR-controlled serial, so there is
        // nothing to protect by withholding it from a draft.
        ->and($invoice->number)->toBe('INV-2026-00001')
        ->and($invoice->vatable_sales_centavos)->toBe(1_000_000)
        ->and($invoice->vat_centavos)->toBe(120_000)
        ->and($invoice->total_centavos)->toBe(1_120_000)
        ->and($invoice->lines()->count())->toBe(1);
});

it('gives the next draft the next number, and approval leaves it alone', function () {
    $first = storedDraft();
    $second = storedDraft();

    expect($first->number)->toBe('INV-2026-00001')
        ->and($second->number)->toBe('INV-2026-00002');

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.approve', $second));

    // Approval posts to the ledger; it no longer allocates anything, so the
    // number the draft was created with is the number that goes out.
    expect($second->refresh()->number)->toBe('INV-2026-00002')
        ->and($second->status)->toBe(Invoice::STATUS_APPROVED);
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

    // withoutLmsMirror() is required — see the note in PaymentControllerTest.
    // The plain attribute override is undone by the factory's afterCreating
    // hook, leaving a user the Gate::before short-circuit never fires for.
    $platformAdmin = User::factory()->withoutLmsMirror()->create();
    $platformAdmin->syncRoles(['platform-admin']);
    $platformAdmin = $platformAdmin->fresh();

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
        ->and($invoice->journal_entry_id)->not->toBeNull();
});

it('reports a closed period as guidance rather than a 500', function () {
    $invoice = storedDraft();
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.approve', $invoice))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_DRAFT);
});

/* ── Voiding ────────────────────────────────────────────────────────── */

it('voids an issued document and reverses the ledger', function () {
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))->post(route('admin.invoices.approve', $invoice));

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.void', $invoice), ['reason' => 'Billed in error'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $invoice->refresh();

    expect($invoice->status)->toBe(Invoice::STATUS_VOIDED)
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

/* ── The student the charges are for ────────────────────────────────── */

it('records the student and snapshots their name', function () {
    $link = ContactStudent::create([
        'contact_id' => Contact::query()->customers()->value('id'),
        'lms_student_id' => 501,
        'student_name' => 'Francesca Inez',
        'relationship' => 'Father',
        'is_primary_payer' => true,
    ]);

    $invoice = storedDraft(['lms_student_id' => 501]);

    expect($invoice->lms_student_id)->toBe(501)
        // Snapshot, so a later correction in the LMS cannot rewrite what a
        // document already said.
        ->and($invoice->student_name)->toBe('Francesca Inez')
        ->and($link->contact_id)->toBe($invoice->contact_id);
});

it('refuses to bill a contact who does not pay for that student', function () {
    // The student picker resolves a payer, but the payer Select stays
    // editable — a stale selection can survive a change of student, and
    // billing a stranger's child is not a mistake to discover from the family.
    ContactStudent::create([
        'contact_id' => Contact::factory()->customer()->create()->getKey(),
        'lms_student_id' => 502,
        'student_name' => 'Someone Else',
        'is_primary_payer' => true,
    ]);

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), invoicePayload(['lms_student_id' => 502]))
        ->assertSessionHasErrors('contact_id');

    expect(Invoice::query()->count())->toBe(0);
});

it('accepts a linked sponsor who is not the primary payer', function () {
    $customerId = Contact::query()->customers()->value('id');

    ContactStudent::create([
        'contact_id' => Contact::factory()->customer()->create()->getKey(),
        'lms_student_id' => 503,
        'student_name' => 'Francesca Inez',
        'is_primary_payer' => true,
    ]);
    ContactStudent::create([
        'contact_id' => $customerId,
        'lms_student_id' => 503,
        'student_name' => 'Francesca Inez',
        'relationship' => 'Sponsor',
        'is_primary_payer' => false,
    ]);

    $invoice = storedDraft(['lms_student_id' => 503]);

    expect($invoice->lms_student_id)->toBe(503);
});

it('still raises an invoice with no student at all', function () {
    // A school also bills organisations for facility hire.
    $invoice = storedDraft();

    expect($invoice->lms_student_id)->toBeNull()
        ->and($invoice->student_name)->toBeNull();
});

it('downloads the same paper it emails', function () {
    // The download and the email attachment go through one renderer on
    // purpose. Two call sites rendering the same view with their own copy of
    // the paper size, the logo rule and the filename is two documents that
    // drift — and the one nobody looks at is the one a customer receives.
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))->post(route('admin.invoices.approve', $invoice));
    $invoice->refresh();

    $downloaded = $this->actingAs(invoiceAuthAs('accountant'))
        ->get(route('admin.invoices.print', $invoice))
        ->getContent();

    $rendered = app(InvoicePdf::class)->bytes($invoice);

    // Byte equality would fail on the embedded creation timestamp, so this
    // compares what the reader gets: same length bracket, same document type.
    expect($downloaded)->toStartWith('%PDF-')
        ->and($rendered)->toStartWith('%PDF-')
        ->and(abs(strlen($downloaded) - strlen($rendered)))->toBeLessThan(2048);
});

it('keeps the printed document small enough to be worth downloading', function () {
    // Guards the font-subsetting flag in InvoicePdf. Without it dompdf embeds
    // each font whole and a one-page invoice is 1.38 MB.
    $invoice = storedDraft();

    $content = $this->actingAs(invoiceAuthAs('accountant'))
        ->get(route('admin.invoices.print', $invoice))
        ->getContent();

    expect(strlen($content))->toBeLessThan(400_000);
});

/* ── The pay link ───────────────────────────────────────────────────── */

it('mints a pay link and returns it on the invoice, not in a toast', function () {
    // The bug this pins: the URL used to be flashed under `success`, which
    // HandleFlashToasts folds into a `toast` prop — so the page's read of
    // `flash.payLink` found nothing, returned early, and the button did
    // nothing at all. The link belongs on the payload.
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))->post(route('admin.invoices.approve', $invoice));

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.pay-link', $invoice->refresh()))
        ->assertRedirect();

    $token = $invoice->refresh()->pay_token;
    expect($token)->not->toBeNull();

    $this->actingAs(invoiceAuthAs('accountant'))
        ->get(route('admin.invoices.show', $invoice))
        ->assertInertia(fn ($page) => $page
            ->where('invoice.pay_url', route('public.pay.show', [
                'slug' => Tenant::current()->slug,
                'token' => $token,
            ])));
});

it('offers no pay link until one is minted', function () {
    // Tokens are created on demand so the number of live public URLs equals
    // the number a person deliberately created. An invoice nobody has shared
    // reports null rather than a guessable address.
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))->post(route('admin.invoices.approve', $invoice));

    $this->actingAs(invoiceAuthAs('accountant'))
        ->get(route('admin.invoices.show', $invoice->refresh()))
        ->assertInertia(fn ($page) => $page->where('invoice.pay_url', null));
});

it('flashes a sentence rather than a bare URL', function () {
    // A toast whose whole body is an address is not a message, and it was
    // standing in for a copy that never happened.
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))->post(route('admin.invoices.approve', $invoice));

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.pay-link', $invoice->refresh()))
        ->assertSessionHas('success', 'Pay link ready.');
});

it('keeps the same pay link when it is copied twice', function () {
    // Re-minting would break a link already in a parent's hands.
    $invoice = storedDraft();
    $this->actingAs(invoiceAuthAs('accountant'))->post(route('admin.invoices.approve', $invoice));

    $this->actingAs(invoiceAuthAs('accountant'))->post(route('admin.invoices.pay-link', $invoice->refresh()));
    $first = $invoice->refresh()->pay_token;

    $this->actingAs(invoiceAuthAs('accountant'))->post(route('admin.invoices.pay-link', $invoice));

    expect($invoice->refresh()->pay_token)->toBe($first);
});

it('refuses a pay link for a supplier bill, as guidance not a 500', function () {
    // A bill is the supplier's own document; there is nobody to send a pay
    // link to. MintInvoicePayToken refuses, and the refusal has to reach the
    // operator as a message rather than as a stack trace.
    $supplier = Contact::factory()->create([
        'name' => 'Acme Supplies',
        'is_customer' => false,
        'is_supplier' => true,
    ]);
    $expense = ChartOfAccount::query()
        ->where('type', ChartOfAccount::TYPE_EXPENSE)
        ->firstOrFail();

    $bill = storedDraft([
        'type' => Invoice::TYPE_PURCHASE,
        'contact_id' => $supplier->id,
    ], [
        'account_id' => $expense->id,
        'tax_rate_id' => null,
    ]);

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.pay-link', $bill))
        ->assertSessionHas('error');
});

/* ── Setting an invoice to repeat ───────────────────────────────────── */

/** @return array<string, mixed> */
function repeatingPayload(array $overrides = [], array $recurrence = []): array
{
    return invoicePayload(array_merge([
        'issue_date' => '2026-08-15',
        'due_date' => '2026-08-30',
        'repeat' => true,
        'recurrence' => array_merge([
            'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
            'name' => null,
            'ends_on' => null,
        ], $recurrence),
    ], $overrides));
}

it('raises the invoice and the schedule together', function () {
    // The page still does what it says: the operator came to bill someone
    // today, and the schedule is an extra instruction on top of that.
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload())
        ->assertSessionHasNoErrors();

    expect(Invoice::query()->count())->toBe(1)
        ->and(RecurringInvoice::query()->count())->toBe(1);
});

it('leaves the schedule out when repeat is not ticked', function () {
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), invoicePayload())
        ->assertSessionHasNoErrors();

    expect(Invoice::query()->count())->toBe(1)
        ->and(RecurringInvoice::query()->count())->toBe(0);
});

it('copies the payer and the lines off the invoice rather than asking again', function () {
    // The whole reason this moved onto the invoice form: typing them twice is
    // how the document and the standing instruction came to disagree.
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload([
            'reference' => 'PO-4471',
            'is_vat_inclusive' => true,
        ]));

    $schedule = RecurringInvoice::query()->sole();
    $invoice = Invoice::query()->sole();

    expect($schedule->contact_id)->toBe($invoice->contact_id)
        ->and($schedule->reference)->toBe('PO-4471')
        ->and($schedule->is_vat_inclusive)->toBeTrue()
        ->and($schedule->type)->toBe(Invoice::TYPE_SALES)
        ->and($schedule->lines)->toHaveCount(1)
        ->and($schedule->lines->first()->unit_price_centavos)
        ->toBe($invoice->lines->first()->unit_price_centavos)
        ->and($schedule->lines->first()->account_id)
        ->toBe($invoice->lines->first()->account_id);
});

it('takes the cadence day from the invoice date, not from the client', function () {
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload([
            'issue_date' => '2026-08-15',
            'due_date' => '2026-08-30',
        ]));

    $schedule = RecurringInvoice::query()->sole();

    expect($schedule->day_of_month)->toBe(15)
        ->and($schedule->starts_on->toDateString())->toBe('2026-08-15');
});

it('inherits the payment terms as the gap between the two dates', function () {
    // An invoice carries two dates and a schedule cannot — it has no single
    // issue date. The gap is the part that generalises.
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload([
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
        ]));

    expect(RecurringInvoice::query()->sole()->due_days)->toBe(30);
});

it('repeats due-on-receipt terms as due on receipt', function () {
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload([
            'due_date' => null,
        ]));

    expect(RecurringInvoice::query()->sole()->due_days)->toBeNull();
});

it('points the cursor at the next period, not at the invoice just raised', function () {
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload());

    expect(RecurringInvoice::query()->sole()->next_run_on->toDateString())
        ->toBe('2026-09-15');
});

it('claims the month the invoice covers, so it is never billed again', function () {
    // The claim IS the guarantee. GenerateDueInvoices works out which period a
    // schedule owes from the number of claim rows and never looks for an
    // existing invoice, so without this the nightly run bills the month twice.
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload());

    $schedule = RecurringInvoice::query()->sole();
    $claim = $schedule->periods()->sole();

    expect($schedule->periods()->count())->toBe(1)
        ->and($claim->period)->toBe('2026-08')
        ->and($claim->invoice_id)->toBe(Invoice::query()->sole()->id)
        ->and($claim->note)->toContain('by hand');
});

it('names the schedule from the invoice when nobody names it', function () {
    // The column is NOT NULL and the invoice form does not ask — the operator
    // is raising a document, not naming a rule.
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload());

    expect(RecurringInvoice::query()->sole()->name)
        ->toContain('Dela Cruz Family')
        ->toContain('Tuition');
});

it('keeps a name the operator did type', function () {
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload([], [
            'name' => 'Grade 7 tuition',
        ]));

    expect(RecurringInvoice::query()->sole()->name)->toBe('Grade 7 tuition');
});

it('quarterly repeats three months on', function () {
    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload([], [
            'frequency' => RecurringInvoice::FREQUENCY_QUARTERLY,
        ]));

    expect(RecurringInvoice::query()->sole()->next_run_on->toDateString())
        ->toBe('2026-11-15');
});

it('rolls the invoice back when the schedule cannot be built', function () {
    // One decision, one transaction. An invoice saved without its claim would
    // be billed again overnight, and an invoice saved without the schedule the
    // operator asked for is a promise quietly not kept. Neither is acceptable,
    // so neither is saved.
    $supplier = Contact::factory()->create([
        'is_customer' => false,
        'is_supplier' => true,
    ]);
    $expense = ChartOfAccount::query()
        ->where('type', ChartOfAccount::TYPE_EXPENSE)
        ->firstOrFail();

    $this->actingAs(invoiceAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingPayload([
            'type' => Invoice::TYPE_PURCHASE,
            'contact_id' => $supplier->id,
            'lines' => [[
                'description' => 'Classroom supplies',
                'quantity' => '1',
                'unit_price_centavos' => 100_000,
                'account_id' => $expense->id,
                'tax_rate_id' => null,
            ]],
        ]))
        ->assertSessionHas('error');

    expect(Invoice::query()->count())->toBe(0)
        ->and(RecurringInvoice::query()->count())->toBe(0);
});
