<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateInvoiceDraft;
use App\Actions\Accounting\SendInvoiceEmail;
use App\Mail\InvoiceIssuedMail;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\RecurringInvoice;
use App\Models\Pas\RecurringInvoiceLine;
use App\Models\Pas\School;
use App\Models\User;
use App\Services\SchoolLogo;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Models\Tenant;

/*
 * The invoice reaching the payer.
 *
 * The two that matter: a hand-made invoice must never send itself just because
 * someone approved it, and a queue retry must never bill a family's inbox
 * twice.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();

    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    $this->income = ChartOfAccount::query()->where('code', '4100')->firstOrFail();
    $this->customer = Contact::factory()->create([
        'name' => 'Dela Cruz Family',
        'email' => 'family@example.test',
        'is_customer' => true,
    ]);
});

function invoiceApprover(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['accountant']);

    return $user;
}

/** A draft raised by a schedule, ready to approve. */
function generatedDraft(array $contactAttributes = []): Invoice
{
    if ($contactAttributes !== []) {
        test()->customer->update($contactAttributes);
    }

    $schedule = RecurringInvoice::factory()->create([
        'contact_id' => test()->customer->id,
        'starts_on' => '2026-08-01',
        'next_run_on' => '2026-08-01',
        'day_of_month' => 1,
    ]);

    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create([
        'account_id' => test()->income->id,
        'unit_price_centavos' => 500_000,
        'tax_rate_id' => null,
    ]);

    test()->artisan('invoices:generate-recurring', ['--date' => '2026-08-01'])->run();

    return Invoice::query()->whereNotNull('recurring_invoice_id')->sole();
}

/**
 * The school the send will read, edited so the send actually sees it.
 *
 * Spatie binds ONE School instance as the current tenant, and every request in
 * a test reads that same object rather than re-resolving it. A query-builder
 * update writes the row without touching the bound instance, so the action
 * would go on reading the old values — which is a test artifact, not the
 * behaviour of a real request, where the tenant is resolved fresh each time.
 */
function sendingSchool(array $attributes): School
{
    $school = Tenant::current();

    expect($school)->toBeInstanceOf(School::class);

    /** @var School $school */
    $school->update($attributes);

    return $school;
}

/** A draft someone typed by hand, built the way the controller builds one. */
function handMadeDraft(): Invoice
{
    return app(CreateInvoiceDraft::class)->execute(
        [
            'type' => Invoice::TYPE_SALES,
            'contact_id' => test()->customer->id,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-16',
            'is_vat_inclusive' => false,
        ],
        [[
            'description' => 'Tuition',
            'quantity' => '1',
            'unit_price_centavos' => 500_000,
            'account_id' => test()->income->id,
            'tax_rate_id' => null,
        ]],
    );
}

/* ── Which invoices send themselves ──────────────────────────────────── */

it('emails the payer when a generated invoice is approved', function () {
    $invoice = generatedDraft();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.approve', $invoice))
        ->assertRedirect();

    Mail::assertQueued(InvoiceIssuedMail::class, fn ($mail): bool => $mail->hasTo('family@example.test'));
});

it('sends nothing when a hand-made invoice is approved', function () {
    // Approving is not the same as sending. An operator who was not ready to
    // send must not have the invoice leave the building because of it.
    $invoice = handMadeDraft();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.approve', $invoice))
        ->assertRedirect();

    Mail::assertNothingQueued();
});

/* ── Sending twice ───────────────────────────────────────────────────── */

it('refuses to send the same invoice a second time', function () {
    // Horizon retries three times. Claiming `sent_at` before the handoff is
    // what stops a family getting the same invoice three times.
    $invoice = generatedDraft();
    $this->actingAs(invoiceApprover())->post(route('admin.invoices.approve', $invoice));

    $message = app(SendInvoiceEmail::class)->execute($invoice->refresh());

    expect($message)->toContain('already sent');
    Mail::assertQueuedCount(1);
});

it('records the send on the invoice', function () {
    $invoice = generatedDraft();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.approve', $invoice));

    expect($invoice->refresh()->sent_at)->not->toBeNull()
        ->and($invoice->status)->toBe(Invoice::STATUS_SENT)
        // STATUS_SENT is still an issued status, so the pay page keeps working.
        ->and($invoice->isIssued())->toBeTrue();
});

/* ── When it cannot send ─────────────────────────────────────────────── */

it('says so when the payer has no email address, without failing the approval', function () {
    $invoice = generatedDraft(['email' => null]);

    $response = $this->actingAs(invoiceApprover())->post(route('admin.invoices.approve', $invoice));

    // The posting stands; only the sending did not happen.
    expect($invoice->refresh()->isIssued())->toBeTrue()
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and($invoice->sent_at)->toBeNull();

    $response->assertSessionHas('success', fn (string $m): bool => str_contains($m, 'no email address'));
    Mail::assertNothingQueued();
});

it('refuses to send a draft', function () {
    // A draft has no pay token and prints "not issued".
    $invoice = handMadeDraft();

    expect(fn () => app(SendInvoiceEmail::class)->execute($invoice))
        ->toThrow(DomainException::class, 'Approve it first');
});

/* ── What the message carries ────────────────────────────────────────── */

it('carries a tokenised pay link', function () {
    $invoice = generatedDraft();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.approve', $invoice));

    Mail::assertQueued(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail) use ($invoice): bool {
        $token = $invoice->refresh()->pay_token;

        return $token !== null
            && str_contains($mail->payUrl, $token)
            && str_contains($mail->payUrl, '/pay/')
            && str_contains($mail->amount, '5,000.00');
    });
});

/* ── Sending by hand ─────────────────────────────────────────────────── */

/** An approved, hand-typed invoice — the state the Send button acts on. */
function approvedByHand(): Invoice
{
    $invoice = handMadeDraft();

    test()->actingAs(invoiceApprover())
        ->post(route('admin.invoices.approve', $invoice));

    return $invoice->refresh();
}

it('sends a hand-made invoice when a person asks for it', function () {
    // The gap this closes: until now only a schedule-generated invoice could
    // ever leave the building, and a typed one had no route out at all.
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice))
        ->assertRedirect()
        ->assertSessionHas('success');

    Mail::assertQueued(InvoiceIssuedMail::class, fn ($mail): bool => $mail->hasTo('family@example.test'));
});

it('sends to the address the operator typed, not the one on file', function () {
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice), ['email' => 'lola@example.test'])
        ->assertRedirect();

    Mail::assertQueued(InvoiceIssuedMail::class, fn ($mail): bool => $mail->hasTo('lola@example.test'));
});

it('leaves the contact record alone when a one-off address is used', function () {
    // A send to a grandparent's address must not rewrite the family's billing
    // email. Editing a contact stays an act on the contacts register.
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice), ['email' => 'lola@example.test']);

    expect($this->customer->refresh()->email)->toBe('family@example.test');
});

it('records where it went and when', function () {
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice), ['email' => 'lola@example.test']);

    expect($invoice->refresh()->sent_to)->toBe('lola@example.test')
        ->and($invoice->sent_at)->not->toBeNull()
        ->and($invoice->status)->toBe(Invoice::STATUS_SENT);
});

it('falls back to the payer when no address is typed', function () {
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice), ['email' => '']);

    expect($invoice->refresh()->sent_to)->toBe('family@example.test');
    Mail::assertQueued(InvoiceIssuedMail::class, fn ($mail): bool => $mail->hasTo('family@example.test'));
});

it('says so when there is no address anywhere, rather than sending nothing quietly', function () {
    // As an error on `email`, not a flash: it is a complaint about the box the
    // operator is looking at, and a validation error is what keeps the send
    // dialog open with the message under that box. A flash would close the
    // dialog and leave a toast to explain a field they can no longer see.
    $this->customer->update(['email' => null]);
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice))
        ->assertSessionHasErrors('email');

    Mail::assertNothingQueued();
    expect($invoice->refresh()->sent_at)->toBeNull();
});

it('names the customer in the no-address complaint', function () {
    // "This customer has no email address" is a sentence about nobody. The
    // operator may have several invoices open and needs to know which family.
    $this->customer->update(['email' => null]);
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice))
        ->assertSessionHasErrors([
            'email' => 'Dela Cruz Family has no email address on file. Type one to send this invoice.',
        ]);
});

it('refuses an address that is not one', function () {
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice), ['email' => 'not-an-address'])
        ->assertSessionHasErrors('email');

    Mail::assertNothingQueued();
});

it('refuses an address longer than the column holds', function () {
    // 160 characters, matching `pas_contacts.email` and `pas_invoices.sent_to`
    // — the send record has to be able to store what was sent to.
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice), [
            'email' => str_repeat('a', 160).'@example.test',
        ])
        ->assertSessionHasErrors('email');

    Mail::assertNothingQueued();
});

/* ── Sending again ───────────────────────────────────────────────────── */

it('re-sends when a person asks, unlike a queue retry', function () {
    // The already-sent guard exists so a retried approval cannot mail the same
    // document twice. It was never meant to answer "they say it never
    // arrived" with a shrug.
    $invoice = approvedByHand();
    $sender = invoiceApprover();

    $this->actingAs($sender)->post(route('admin.invoices.send', $invoice));
    $this->actingAs($sender)->post(route('admin.invoices.send', $invoice), [
        'email' => 'corrected@example.test',
    ]);

    Mail::assertQueuedCount(2);
    expect($invoice->refresh()->sent_to)->toBe('corrected@example.test');
});

it('does not walk a partially paid invoice back to sent', function () {
    // `sent` reads as "nothing has arrived". Promoting a paid-in-part invoice
    // back to it on a re-send would hide money in every receivable report,
    // because those follow the status rather than the allocations.
    $invoice = approvedByHand();
    $invoice->forceFill(['status' => Invoice::STATUS_PARTIALLY_PAID])->save();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice));

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_PARTIALLY_PAID)
        ->and($invoice->sent_at)->not->toBeNull();
    Mail::assertQueuedCount(1);
});

/* ── What cannot be sent ─────────────────────────────────────────────── */

it('refuses to send a draft through the route', function () {
    // Not yet a claim on anybody, and it can still change after it lands in
    // an inbox. The policy refuses before the action is reached.
    $invoice = handMadeDraft();

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice))
        ->assertForbidden();

    Mail::assertNothingQueued();
});

it('refuses to send a purchase bill', function () {
    // A supplier's own document. There is nobody to send it to, and
    // MintInvoicePayToken refuses to build a pay link for one.
    $supplier = Contact::factory()->create([
        'name' => 'Acme Supplies',
        'email' => 'billing@acme.test',
        'is_customer' => false,
        'is_supplier' => true,
    ]);

    $bill = app(CreateInvoiceDraft::class)->execute(
        [
            'type' => Invoice::TYPE_PURCHASE,
            'contact_id' => $supplier->id,
            'issue_date' => '2026-08-01',
            'is_vat_inclusive' => false,
        ],
        [[
            'description' => 'Classroom supplies',
            'quantity' => '1',
            'unit_price_centavos' => 100_000,
            'account_id' => ChartOfAccount::query()->where('type', ChartOfAccount::TYPE_EXPENSE)->firstOrFail()->id,
            'tax_rate_id' => null,
        ]],
    );

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.approve', $bill));

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $bill->refresh()))
        ->assertForbidden();
});

it('refuses to send a voided invoice', function () {
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.void', $invoice), [
        'reason' => 'Billed in error',
    ]);

    $this->actingAs(invoiceApprover())
        ->post(route('admin.invoices.send', $invoice->refresh()))
        ->assertForbidden();

    Mail::assertNothingQueued();
});

/* ── Who may send ────────────────────────────────────────────────────── */

it('lets a payroll officer send, because they raised the invoice', function () {
    $invoice = approvedByHand();

    $officer = User::factory()->create();
    $officer->syncRoles(['payroll-officer']);

    $this->actingAs($officer)
        ->post(route('admin.invoices.send', $invoice))
        ->assertRedirect();

    Mail::assertQueuedCount(1);
});

it('refuses an auditor, who is read-only', function () {
    $invoice = approvedByHand();

    $auditor = User::factory()->create();
    $auditor->syncRoles(['auditor']);

    $this->actingAs($auditor)
        ->post(route('admin.invoices.send', $invoice))
        ->assertForbidden();

    Mail::assertNothingQueued();
});

/* ── The school on the message ──────────────────────────────────────── */

it('sends under the school name, from the address this host may send as', function () {
    // The From address must stay the authenticated sender. Putting the
    // school's own address there fails SPF and DKIM at every major provider —
    // nothing in the school's DNS names this server — and an invoice in a
    // spam folder is an invoice nobody pays.
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.send', $invoice));

    Mail::assertQueued(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail): bool {
        $envelope = $mail->envelope();

        return $envelope->from?->address === config('mail.from.address')
            && $envelope->from?->name === $mail->schoolName;
    });
});

it('points replies at the school office', function () {
    // The body tells the payer to reply to the message. Without this, that
    // reply reaches the platform's own mailbox, which nobody at the school
    // reads.
    sendingSchool(['email' => 'office@stmarys.test']);

    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.send', $invoice));

    Mail::assertQueued(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail): bool {
        $replyTo = $mail->envelope()->replyTo;

        return count($replyTo) === 1 && $replyTo[0]->address === 'office@stmarys.test';
    });
});

it('sets no reply-to when the school has not given an address', function () {
    // Better than a Reply-To nobody reads: the parent's mail client then
    // offers the From address, and the school has one visible gap to fill
    // rather than a silently misrouted conversation.
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.send', $invoice));

    Mail::assertQueued(
        InvoiceIssuedMail::class,
        fn (InvoiceIssuedMail $mail): bool => $mail->envelope()->replyTo === [],
    );
});

it('carries the school logo as a URL the inbox can fetch', function () {
    Storage::fake(SchoolLogo::DISK);

    $school = Tenant::current();
    sendingSchool([
        'logo_path' => app(SchoolLogo::class)->store($school, UploadedFile::fake()->image('crest.png', 120, 120)),
    ]);

    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.send', $invoice));

    Mail::assertQueued(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail): bool {
        // Absolute, because it is fetched from an inbox rather than from a
        // page on this origin — a relative path renders as a broken image.
        return str_starts_with((string) $mail->logoUrl, 'http')
            && str_contains((string) $mail->logoUrl, 'logo-');
    });
});

it('sends without a logo rather than a broken one', function () {
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.send', $invoice));

    Mail::assertQueued(
        InvoiceIssuedMail::class,
        fn (InvoiceIssuedMail $mail): bool => $mail->logoUrl === null,
    );
});

it('puts the logo in the message and keeps it out of the plain-text half', function () {
    // The markdown mailable renders twice, and the text renderer does not
    // strip tags. An <img> in the header slot would arrive as literal markup
    // for anyone reading mail as text, so the slot stays the school's name and
    // the logo travels as a component attribute.
    $mail = new InvoiceIssuedMail(
        schoolName: 'St. Marys Academy',
        invoiceNumber: 'INV-2026-00001',
        payerName: 'Dela Cruz Family',
        amount: '₱5,000.00',
        dueDate: null,
        studentName: null,
        payUrl: 'http://payroll-system.test/pay/token',
        logoUrl: 'http://payroll-system.test/storage/schools/1/logo-abc.png',
        schoolEmail: 'office@stmarys.test',
    );

    $html = $mail->render();

    expect($html)->toContain('<img src="http://payroll-system.test/storage/schools/1/logo-abc.png"')
        ->and($html)->toContain('alt="St. Marys Academy"')
        // Height-driven, so a wordmark is not squashed into the theme's
        // 75x75 `.logo` square.
        ->and($html)->toContain('height: 56px; width: auto;');

    $text = app(Markdown::class)
        ->renderText('mail.invoice-issued', (array) $mail->buildViewData())
        ->toHtml();

    expect($text)->not->toContain('<img')
        ->and($text)->toContain('St. Marys Academy');
});

it('falls back to the school name in type when there is no logo', function () {
    $mail = new InvoiceIssuedMail(
        schoolName: 'St. Marys Academy',
        invoiceNumber: 'INV-2026-00001',
        payerName: 'Dela Cruz Family',
        amount: '₱5,000.00',
        dueDate: null,
        studentName: null,
        payUrl: 'http://payroll-system.test/pay/token',
    );

    expect($mail->render())->not->toContain('<img')
        ->toContain('St. Marys Academy');
});

it('names the school office in the body when it has an address', function () {
    $withAddress = new InvoiceIssuedMail(
        schoolName: 'St. Marys Academy',
        invoiceNumber: 'INV-1',
        payerName: 'Ana',
        amount: '₱1.00',
        dueDate: null,
        studentName: null,
        payUrl: 'http://payroll-system.test/pay/token',
        schoolEmail: 'office@stmarys.test',
    );

    expect($withAddress->render())->toContain('office@stmarys.test');
});

/* ── The attached document ──────────────────────────────────────────── */

it('attaches the invoice as a PDF', function () {
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.send', $invoice));

    Mail::assertQueued(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail): bool {
        $attachments = $mail->attachments();

        return count($attachments) === 1
            && $attachments[0]->as === $mail->pdfFilename
            && $attachments[0]->mime === 'application/pdf';
    });
});

it('attaches a real PDF, not a promise of one', function () {
    // The bytes are rendered in-request and carried on the mailable, so this
    // asserts the actual document rather than that some string was set.
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.send', $invoice));

    Mail::assertQueued(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail): bool {
        $bytes = base64_decode((string) $mail->pdfBase64, true);

        return is_string($bytes) && str_starts_with($bytes, '%PDF-');
    });
});

it('names the attachment after the invoice, because that is what a reply cites', function () {
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.send', $invoice));

    Mail::assertQueued(
        InvoiceIssuedMail::class,
        fn (InvoiceIssuedMail $mail): bool => $mail->pdfFilename === 'invoice-'.$invoice->refresh()->number.'.pdf',
    );
});

it('keeps the attachment small enough to reach a phone', function () {
    // `barryvdh/laravel-dompdf` ships `enable_font_subsetting => false`, which
    // embeds every font whole: 1.38 MB for a one-page invoice. `InvoicePdf`
    // turns subsetting back on per render, which is the difference between a
    // 32 KB attachment and one a mail server may refuse.
    $invoice = approvedByHand();

    $this->actingAs(invoiceApprover())->post(route('admin.invoices.send', $invoice));

    Mail::assertQueued(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail): bool {
        $bytes = (string) base64_decode((string) $mail->pdfBase64, true);

        return strlen($bytes) < 400_000;
    });
});

it('sends the link alone rather than failing when there is no document', function () {
    // A corrupt payload must not take the send down: the body's link still
    // pays the invoice, and refusing to deliver it over a damaged copy of a
    // document the recipient can already reach is the worse outcome.
    $mail = new InvoiceIssuedMail(
        schoolName: 'St. Marys Academy',
        invoiceNumber: 'INV-1',
        payerName: 'Ana',
        amount: '₱1.00',
        dueDate: null,
        studentName: null,
        payUrl: 'http://payroll-system.test/pay/token',
        pdfBase64: 'not base64 at all !!!',
        pdfFilename: 'invoice-INV-1.pdf',
    );

    expect($mail->attachments())->toBe([]);
});
