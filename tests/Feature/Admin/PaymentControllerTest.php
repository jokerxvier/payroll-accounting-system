<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentAllocation;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use App\Models\User;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * /admin/payments (Phase 5 Slice 7).
 *
 * Pinned:
 *  - role gates match App\Policies\Pas\AccountingRoles
 *  - only a draft is editable, including for a platform admin
 *  - allocation refusals come back as guidance, not a 500
 *  - posting settles the documents; voiding puts them back
 *  - the cash account must be an asset the software does not own
 */

beforeEach(function (): void {
    PaymentAllocation::query()->withoutGlobalScopes()->delete();
    Payment::query()->withoutGlobalScopes()->delete();
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
    $this->cash = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
});

function paymentAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function ctrlInvoice(int $totalCentavos, ?Contact $contact = null): Invoice
{
    $invoice = Invoice::factory()->create([
        'contact_id' => ($contact ?? test()->customer)->id,
        'issue_date' => '2026-08-10',
        'status' => Invoice::STATUS_APPROVED,
        'number' => 'SI-'.fake()->unique()->numerify('######'),
        'vatable_sales_centavos' => $totalCentavos,
        'total_centavos' => $totalCentavos,
    ]);

    InvoiceLine::factory()->forInvoice($invoice)->create([
        'account_id' => ChartOfAccount::query()->where('code', '4100')->value('id'),
        'unit_price_centavos' => $totalCentavos,
        'line_net_centavos' => $totalCentavos,
    ]);

    return $invoice->refresh();
}

/** @return array<string, mixed> */
function paymentPayload(array $overrides = [], array $allocations = []): array
{
    return array_merge([
        'type' => Payment::TYPE_RECEIPT,
        'contact_id' => test()->customer->id,
        'payment_date' => '2026-08-15',
        'amount_centavos' => 500_000,
        'cash_account_id' => test()->cash->id,
        'method' => Payment::METHOD_CASH,
        'reference' => null,
        'notes' => null,
        'allocations' => $allocations,
    ], $overrides);
}

function storedPayment(array $overrides = [], array $allocations = []): Payment
{
    test()->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.store'), paymentPayload($overrides, $allocations))
        ->assertSessionHasNoErrors();

    return Payment::query()->latest('id')->firstOrFail();
}

/* ── Access ─────────────────────────────────────────────────────────── */

it('lets every accounting-view role see the list', function (string $role) {
    $this->actingAs(paymentAuthAs($role))
        ->get(route('admin.payments.index'))
        ->assertOk();
})->with(['super-admin', 'accountant', 'payroll-officer', 'auditor']);

it('refuses roles outside the accounting set', function (string $role) {
    $this->actingAs(paymentAuthAs($role))
        ->get(route('admin.payments.index'))
        ->assertForbidden();
})->with(['hr', 'employee']);

it('lets a payroll officer key a payment but not post it', function () {
    // Maker-checker: MANAGE keys and allocates, POST_LEDGER commits.
    $officer = paymentAuthAs('payroll-officer');
    $payment = storedPayment();

    $this->actingAs($officer)->get(route('admin.payments.edit', $payment))->assertOk();
    $this->actingAs($officer)
        ->post(route('admin.payments.post', $payment))
        ->assertForbidden();
});

/* ── Drafting and allocating ────────────────────────────────────────── */

it('stores a draft and records its allocations', function () {
    $invoice = ctrlInvoice(300_000);
    $payment = storedPayment([], [
        ['invoice_id' => $invoice->id, 'amount_centavos' => 300_000],
    ]);

    expect($payment->status)->toBe(Payment::STATUS_DRAFT)
        ->and($payment->allocated_centavos)->toBe(300_000)
        ->and($payment->unallocated()->centavos())->toBe(200_000)
        // Still a draft, so the invoice is untouched.
        ->and($invoice->refresh()->amount_paid_centavos)->toBe(0);
});

it('accepts a payment allocated to nothing', function () {
    // An advance received before any invoice exists.
    $payment = storedPayment();

    expect($payment->allocated_centavos)->toBe(0)
        ->and($payment->unallocated()->centavos())->toBe(500_000);
});

it('reports an over-allocation as guidance rather than a 500', function () {
    $invoice = ctrlInvoice(100_000);

    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.store'), paymentPayload([], [
            ['invoice_id' => $invoice->id, 'amount_centavos' => 400_000],
        ]))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Payment::query()->count())->toBe(0);
});

it("reports another contact's invoice as guidance", function () {
    $other = Contact::factory()->create(['name' => 'Santos Family']);
    $invoice = ctrlInvoice(300_000, $other);

    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.store'), paymentPayload([], [
            ['invoice_id' => $invoice->id, 'amount_centavos' => 300_000],
        ]))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Payment::query()->count())->toBe(0);
});

it('replaces allocations when a draft is edited', function () {
    $invoice = ctrlInvoice(500_000);
    $payment = storedPayment([], [
        ['invoice_id' => $invoice->id, 'amount_centavos' => 300_000],
    ]);

    $this->actingAs(paymentAuthAs('accountant'))
        ->put(route('admin.payments.update', $payment), paymentPayload([], [
            ['invoice_id' => $invoice->id, 'amount_centavos' => 100_000],
        ]))
        ->assertSessionHasNoErrors();

    expect($payment->refresh()->allocated_centavos)->toBe(100_000)
        ->and($payment->allocations()->count())->toBe(1);
});

it('deletes a draft and its allocations together', function () {
    $invoice = ctrlInvoice(300_000);
    $payment = storedPayment([], [
        ['invoice_id' => $invoice->id, 'amount_centavos' => 300_000],
    ]);

    $this->actingAs(paymentAuthAs('accountant'))
        ->delete(route('admin.payments.destroy', $payment))
        ->assertRedirect();

    expect(Payment::query()->count())->toBe(0)
        // The observer removes them through Eloquent so each writes an audit
        // row, rather than letting the FK cascade take them silently.
        ->and(PaymentAllocation::query()->count())->toBe(0);
});

/* ── Validation ─────────────────────────────────────────────────────── */

it('refuses a payment of nothing', function () {
    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.store'), paymentPayload(['amount_centavos' => 0]))
        ->assertSessionHasErrors('amount_centavos');
});

it('refuses a receipt against a contact who is not a customer', function () {
    $supplier = Contact::factory()->supplier()->create(['name' => 'Paper Supplier']);

    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.store'), paymentPayload(['contact_id' => $supplier->id]))
        ->assertSessionHasErrors('contact_id');
});

it('refuses a cash account that is not an asset', function () {
    // Receiving money "into" an income account balances arithmetically and
    // describes something that never happened.
    $income = ChartOfAccount::query()->where('code', '4100')->firstOrFail();

    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.store'), paymentPayload(['cash_account_id' => $income->id]))
        ->assertSessionHasErrors('cash_account_id');
});

it('refuses an account belonging to another school', function () {
    $otherSchool = School::factory()->create();
    $foreign = ChartOfAccount::factory()->asset()->create([
        'school_id' => $otherSchool->id,
        'code' => '1999',
    ]);

    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.store'), paymentPayload(['cash_account_id' => $foreign->id]))
        ->assertSessionHasErrors('cash_account_id');
});

it('does not offer control accounts as somewhere money can move', function () {
    $this->actingAs(paymentAuthAs('accountant'))
        ->get(route('admin.payments.create'))
        ->assertInertia(function ($page) {
            $codes = collect($page->toArray()['props']['cashAccountOptions'])->pluck('code');

            expect($codes)->toContain('1100')
                // Accounts Receivable is an asset, but crediting it directly
                // as if it were a bank account is what makes a receivable
                // stop meaning anything.
                ->not->toContain('1200')
                ->not->toContain('1450');
        });
});

/* ── Immutability once posted ───────────────────────────────────────── */

it('refuses to edit a posted payment', function () {
    $payment = storedPayment();
    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.post', $payment))
        ->assertRedirect();

    $accountant = paymentAuthAs('accountant');

    $this->actingAs($accountant)->get(route('admin.payments.edit', $payment->refresh()))->assertForbidden();
    $this->actingAs($accountant)
        ->put(route('admin.payments.update', $payment), paymentPayload())
        ->assertForbidden();
    $this->actingAs($accountant)
        ->delete(route('admin.payments.destroy', $payment))
        ->assertForbidden();
});

it('refuses a platform admin the same edits, despite Gate::before', function () {
    $payment = storedPayment();
    $this->actingAs(paymentAuthAs('accountant'))->post(route('admin.payments.post', $payment));

    // withoutLmsMirror(), not create(['lms_user_id' => null]): the factory's
    // own afterCreating hook backfills lms_user_id = id *after* the attribute
    // override, so the plain form produces an LMS-derived user that never
    // reaches the Gate::before short-circuit — and this test would then pass
    // without ever exercising the bypass it exists to guard.
    $platformAdmin = User::factory()->withoutLmsMirror()->create();
    $platformAdmin->syncRoles(['platform-admin']);
    $platformAdmin = $platformAdmin->fresh();

    $this->actingAs($platformAdmin)
        ->put(route('admin.payments.update', $payment->refresh()), paymentPayload())
        ->assertForbidden();
    $this->actingAs($platformAdmin)
        ->delete(route('admin.payments.destroy', $payment))
        ->assertForbidden();
});

it('offers no edit or delete action on a posted payment', function () {
    $payment = storedPayment();
    $this->actingAs(paymentAuthAs('accountant'))->post(route('admin.payments.post', $payment));

    $this->actingAs(paymentAuthAs('accountant'))
        ->get(route('admin.payments.index'))
        ->assertInertia(fn ($page) => $page
            ->where('payments.data.0.can.update', false)
            ->where('payments.data.0.can.delete', false)
            ->where('payments.data.0.can.void', true));
});

/* ── Posting and voiding through the endpoints ──────────────────────── */

it('posts a payment and settles the documents it was allocated to', function () {
    $invoice = ctrlInvoice(500_000);
    $payment = storedPayment([], [
        ['invoice_id' => $invoice->id, 'amount_centavos' => 500_000],
    ]);

    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.post', $payment))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($payment->refresh()->status)->toBe(Payment::STATUS_POSTED)
        ->and($payment->journal_entry_id)->not->toBeNull()
        ->and($invoice->refresh()->status)->toBe(Invoice::STATUS_PAID);
});

it('reports a closed period as guidance rather than a 500', function () {
    $payment = storedPayment();
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);

    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.post', $payment))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($payment->refresh()->status)->toBe(Payment::STATUS_DRAFT);
});

it('voids a posted payment and puts the documents back', function () {
    $invoice = ctrlInvoice(500_000);
    $payment = storedPayment([], [
        ['invoice_id' => $invoice->id, 'amount_centavos' => 500_000],
    ]);
    $this->actingAs(paymentAuthAs('accountant'))->post(route('admin.payments.post', $payment));

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_PAID);

    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.void', $payment->refresh()), ['reason' => 'Cheque bounced'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($payment->refresh()->status)->toBe(Payment::STATUS_VOIDED)
        ->and($payment->void_reason)->toBe('Cheque bounced')
        ->and($invoice->refresh()->status)->toBe(Invoice::STATUS_APPROVED)
        ->and($invoice->amount_paid_centavos)->toBe(0)
        // Original plus reversal.
        ->and(JournalEntry::query()->count())->toBe(2);
});

it('refuses to void a draft', function () {
    $payment = storedPayment();

    $this->actingAs(paymentAuthAs('accountant'))
        ->post(route('admin.payments.void', $payment))
        ->assertForbidden();
});

/* ── The edit page loads what the grid needs ────────────────────────── */

it('loads the outstanding documents for the payment\'s contact', function () {
    $invoice = ctrlInvoice(300_000);
    $payment = storedPayment([], [
        ['invoice_id' => $invoice->id, 'amount_centavos' => 100_000],
    ]);

    $this->actingAs(paymentAuthAs('accountant'))
        ->get(route('admin.payments.edit', $payment))
        ->assertInertia(fn ($page) => $page
            ->where('outstandingInvoices.0.id', $invoice->id)
            ->where('outstandingInvoices.0.balance_due_centavos', 300_000)
            ->where('payment.allocations.0.amount_centavos', 100_000));
});
