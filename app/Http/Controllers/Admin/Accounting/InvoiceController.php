<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Accounting\ApproveInvoice;
use App\Actions\Accounting\CreateInvoiceDraft;
use App\Actions\Accounting\SendInvoiceEmail;
use App\Actions\Accounting\StartInvoiceSchedule;
use App\Actions\Accounting\VoidInvoice;
use App\Actions\Payments\MintInvoicePayToken;
use App\Exceptions\ClosedAccountingPeriodException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\InvoiceRequest;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\ContactStudent;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\PaymentAllocation;
use App\Models\Pas\RecurringInvoice;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use App\Services\Accounting\InvoiceHeaderAttributes;
use App\Services\Accounting\InvoiceLineWriter;
use App\Support\DayBoundary;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Admin surface for invoices and bills — Phase 5 Slice 5.
 *
 * Full pages rather than sheets, for the same reason the journal uses them:
 * a document is a variable-length grid of description, quantity, price,
 * account, and tax columns, and a 640px panel makes the money columns — the
 * thing anyone actually reads down the page — unreadable.
 *
 * The totals shown while drafting are computed server-side on save and
 * displayed from the stored figures. The client shows a running preview, but
 * it is never authoritative: {@see ApproveInvoice} recomputes from the lines
 * before it issues a number, so a draft can never be issued carrying
 * arithmetic the server did not produce.
 *
 * Domain refusals — a closed period, an exhausted serial range, a
 * counterparty of the wrong kind — come back as flash errors rather than
 * 500s, because every one of them is something an operator can act on.
 */
final class InvoiceController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Invoice::class);

        $type = (string) $request->query('type', Invoice::TYPE_SALES);
        $type = in_array($type, Invoice::TYPES, true) ? $type : Invoice::TYPE_SALES;
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('search', ''));

        // The invoice dashboard's Top Outstanding table links here with a
        // contact, so a payer's name opens their documents rather than the
        // whole list. Read as an int and dropped when it is not one — the
        // tenant scope does the rest, so a foreign contact simply matches
        // nothing.
        $contactId = (int) $request->query('contact_id', 0);

        // Bounds are inclusive at both ends, and go through DayBoundary
        // rather than a bare 'Y-m-d': a `date` column compared to a plain
        // date string drops the last day of the range under SQLite.
        $from = DayBoundary::parse($request->query('from'));
        $to = DayBoundary::parse($request->query('to'));

        $after = $from !== null ? DayBoundary::start($from) : null;
        $before = $to !== null ? DayBoundary::end($to) : null;

        $invoices = Invoice::query()
            ->with(['contact:id,name'])
            ->ofType($type)
            ->when(
                in_array($status, Invoice::STATUSES, true),
                fn ($query) => $query->where('status', $status),
            )
            ->when($contactId > 0, fn ($query) => $query->where('contact_id', $contactId))
            ->matching($search)
            ->when($after !== null, fn ($query) => $query->where('issue_date', '>=', $after))
            ->when($before !== null, fn ($query) => $query->where('issue_date', '<=', $before))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Invoice $invoice): array => $this->summarise($invoice));

        return Inertia::render('admin/accounting/invoices/index', [
            'invoices' => $invoices,
            'filters' => [
                'type' => $type,
                'search' => $search !== '' ? $search : null,
                'contact_id' => $contactId > 0 ? $contactId : null,
                'status' => $status !== '' ? $status : null,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'can' => [
                'create' => Gate::allows('create', Invoice::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Invoice::class);

        $type = (string) $request->query('type', Invoice::TYPE_SALES);
        $type = in_array($type, Invoice::TYPES, true) ? $type : Invoice::TYPE_SALES;

        return Inertia::render('admin/accounting/invoices/create', [
            'type' => $type,
            ...$this->formOptions($type),
        ]);
    }

    public function store(
        InvoiceRequest $request,
        CreateInvoiceDraft $draft,
        StartInvoiceSchedule $schedules,
    ): RedirectResponse {
        Gate::authorize('create', Invoice::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var ?array<string, mixed> $recurrence */
        $recurrence = ($data['repeat'] ?? false) === true && isset($data['recurrence'])
            ? (array) $data['recurrence']
            : null;

        if ($recurrence !== null) {
            // The page not offering a control is not the same as the server
            // refusing it. Both abilities gate on AccountingRoles::MANAGE, so
            // this never fires for someone who reached the form legitimately.
            Gate::authorize('create', RecurringInvoice::class);
        }

        // One transaction: the draft and the instruction to repeat it are one
        // decision, and half of it is worse than neither — a schedule with no
        // claimed first period would bill the month again tonight, and an
        // invoice saved without the schedule the operator asked for is a
        // promise quietly not kept.
        try {
            [$invoice, $schedule] = DB::transaction(
                function () use ($draft, $schedules, $data, $recurrence): array {
                    $invoice = $draft->execute($data, (array) $data['lines']);

                    return [
                        $invoice,
                        $recurrence === null ? null : $schedules->execute($invoice, $recurrence),
                    ];
                },
            );
        } catch (DomainException $e) {
            // Refusing to repeat a bill, say. Actionable guidance rather than
            // a 500, and the draft rolls back with it: the operator asked for
            // a document AND a standing instruction, and got neither.
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = sprintf(
            'Draft %s saved. Approve it to post it to the ledger.',
            $invoice->number,
        );

        if ($schedule !== null) {
            $message .= sprintf(
                ' It repeats %s — next invoice %s.',
                $schedule->frequency,
                $schedule->next_run_on->format('j F Y'),
            );
        }

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', $message);
    }

    public function show(Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load([
            'contact:id,name,tin,email,address',
            'lines.account:id,code,name',
            'lines.taxRate:id,code,name,rate_bps,type',
            'journalEntry:id,entry_number,status',
            // Slice 7 — what has actually been applied to this document.
            // Only posted payments are shown: a draft settles nothing, and
            // listing one here would imply money that has not arrived.
            'allocations.payment:id,reference,payment_date,status,type',
        ]);

        return Inertia::render('admin/accounting/invoices/show', [
            'invoice' => $this->detail($invoice),
        ]);
    }

    public function edit(Invoice $invoice): Response
    {
        Gate::authorize('update', $invoice);
        $this->assertMutable($invoice);

        $invoice->load('lines');

        return Inertia::render('admin/accounting/invoices/edit', [
            'invoice' => [
                'id' => $invoice->id,
                'type' => $invoice->type,
                'contact_id' => $invoice->contact_id,
                'lms_student_id' => $invoice->lms_student_id,
                'reference' => $invoice->reference,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'is_vat_inclusive' => $invoice->is_vat_inclusive,
                'notes' => $invoice->notes,
                'terms' => $invoice->terms,
                'lines' => $invoice->lines->map(fn (InvoiceLine $line): array => [
                    'description' => $line->description,
                    'quantity' => (string) $line->quantity,
                    'unit_price_centavos' => $line->unit_price_centavos,
                    'account_id' => $line->account_id,
                    'tax_rate_id' => $line->tax_rate_id,
                ])->values(),
            ],
            ...$this->formOptions($invoice->type),
        ]);
    }

    public function update(
        InvoiceRequest $request,
        Invoice $invoice,
        InvoiceHeaderAttributes $header,
        InvoiceLineWriter $lines,
    ): RedirectResponse {
        Gate::authorize('update', $invoice);
        $this->assertMutable($invoice);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        DB::transaction(function () use ($invoice, $data, $header, $lines): void {
            $invoice->update($header->fromValidated($data));

            $lines->replace($invoice, (array) $data['lines']);
        });

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', 'Draft updated.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        Gate::authorize('delete', $invoice);
        $this->assertMutable($invoice);

        $type = $invoice->type;

        DB::transaction(fn () => $invoice->delete());

        return redirect()
            ->route('admin.invoices.index', ['type' => $type])
            ->with('success', 'Draft deleted.');
    }

    public function approve(
        Invoice $invoice,
        ApproveInvoice $action,
        SendInvoiceEmail $mailer,
    ): RedirectResponse {
        Gate::authorize('approve', $invoice);

        try {
            $approved = $action->execute($invoice, (int) auth()->id());
        } catch (ClosedAccountingPeriodException|DomainException|RuntimeException $e) {
            // Each of these is actionable: register a series, extend the
            // Authority To Print, reopen the period, fix the chart. None of
            // them is a bug worth a 500.
            return redirect()
                ->route('admin.invoices.show', $invoice)
                ->with('error', $e->getMessage());
        }

        $message = "{$approved->number} approved and posted to the ledger.";

        // Only a document a schedule raised sends itself. Approving an invoice
        // typed by hand behaves exactly as it did before recurring billing
        // existed — an operator who was not ready to send one must not have it
        // leave the building because they approved it.
        //
        // Deliberately outside ApproveInvoice: that action's transaction has
        // committed by the time execution reaches here, which is the only safe
        // moment to mint a token and hand anything to a mail server.
        if ($approved->recurring_invoice_id !== null) {
            try {
                $message .= ' '.$mailer->execute($approved);
            } catch (DomainException $e) {
                // The ledger posting stands. Failing to send is a separate
                // problem and is reported as one.
                return redirect()
                    ->route('admin.invoices.show', $approved)
                    ->with('error', $message.' It could not be sent: '.$e->getMessage());
            }
        }

        return redirect()
            ->route('admin.invoices.show', $approved)
            ->with('success', $message);
    }

    /**
     * Mints the customer-facing pay link and hands it back to be copied.
     *
     * The route has existed since the payments slice; the method it points at
     * did not, so pressing it was a 500 waiting to happen and
     * `MintInvoicePayToken` had no callers at all.
     */
    public function payLink(Invoice $invoice, MintInvoicePayToken $tokens): RedirectResponse
    {
        Gate::authorize('view', $invoice);

        $tenant = Tenant::current();

        if (! $tenant instanceof School) {
            return back()->with('error', 'No school is current, so a pay link cannot be built.');
        }

        try {
            // Minted for its effect on the invoice, not for its return value:
            // the link reaches the page through `detail()`'s `pay_url` on the
            // re-render this redirect triggers. Flashing the URL here instead
            // put a bare address in a toast and left the page with nothing to
            // copy — `HandleFlashToasts` folds every flash key into `toast`,
            // so the `flash.payLink` the page was reading never existed.
            $tokens->execute($invoice);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pay link ready.');
    }

    /**
     * Email an issued invoice to the person who has to pay it.
     *
     * The address is optional on the wire: an operator who does not touch the
     * field is sending to the one the form showed them, which is the payer's.
     * A typed address is used for this send only — it is deliberately not
     * written back to the contact, because a one-off send to a grandparent
     * must not quietly rewrite the family's billing email.
     *
     * `SendInvoiceEmail` treats an explicit address as permission to send
     * again, so pressing this on an already-sent invoice re-sends it. That is
     * the case this exists for: "they never got it", or it went to a typo.
     */
    public function send(Request $request, Invoice $invoice, SendInvoiceEmail $mailer): RedirectResponse
    {
        Gate::authorize('send', $invoice);

        /** @var array{email?: ?string} $validated */
        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        $contact = $invoice->contact;
        $typed = trim((string) ($validated['email'] ?? ''));
        $recipient = $typed !== '' ? $typed : $contact?->email;

        // A refusal goes back as an error on `email` when typing an address
        // would fix it, and as a flash when nothing the operator can type
        // would. The difference is not cosmetic: a validation error keeps the
        // send dialog open with the message under the box being complained
        // about, while a flash closes it — which is right for "this document
        // cannot be sent at all" and wrong for "that address is no good".
        if ($contact === null) {
            return back()->with('error', sprintf(
                '%s has no payer on record, so there is nobody to send it to.',
                $invoice->number ?? 'This invoice',
            ));
        }

        if ($recipient === null || $recipient === '') {
            // Raised here rather than left to the action, whose own message
            // for this case is worded for an approval that has just happened.
            throw ValidationException::withMessages([
                'email' => sprintf(
                    '%s has no email address on file. Type one to send this invoice.',
                    $contact->name,
                ),
            ]);
        }

        try {
            $message = $mailer->execute($invoice, $recipient);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    public function void(Request $request, Invoice $invoice, VoidInvoice $action): RedirectResponse
    {
        Gate::authorize('void', $invoice);

        $reversalDate = $request->filled('reversal_date')
            ? CarbonImmutable::parse((string) $request->input('reversal_date'))
            : null;

        try {
            $voided = $action->execute(
                $invoice,
                (int) auth()->id(),
                (string) $request->input('reason', ''),
                $reversalDate,
            );
        } catch (ClosedAccountingPeriodException|DomainException $e) {
            return redirect()
                ->route('admin.invoices.show', $invoice)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.invoices.show', $voided)
            ->with('success', "{$voided->number} voided. The ledger entry has been reversed and the number stays on record.");
    }

    /**
     * Refuse to touch a document that has already been issued.
     *
     * `InvoicePolicy` folds this same predicate into `update` and `delete`,
     * but the policy alone is not enough: the `Gate::before` short-circuit in
     * `AppServiceProvider::registerPlatformAdminGate()` returns true for
     * every ability a platform admin asks about, sailing straight past the
     * state half of the check. Without this guard a platform admin could
     * rewrite the figures on a numbered document a customer is holding.
     *
     * Editing an issued document is not a permission anyone holds, so the
     * refusal belongs here — outside authorization entirely — rather than
     * being expressed as a role that lacks it.
     */
    private function assertMutable(Invoice $invoice): void
    {
        abort_if(
            ! $invoice->isMutable(),
            403,
            sprintf(
                '%s has been issued. Correct it by voiding it and raising a replacement, never by editing it.',
                $invoice->number ?? 'This document',
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function summarise(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'type' => $invoice->type,
            'number' => $invoice->number,
            // So an officer reviewing forty drafts on a Monday morning can
            // see which of them a schedule raised overnight.
            'is_recurring' => $invoice->recurring_invoice_id !== null,
            'reference' => $invoice->reference,
            'contact_name' => $invoice->contact?->name,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status,
            'total_centavos' => $invoice->total_centavos,
            'amount_paid_centavos' => $invoice->amount_paid_centavos,
            'balance_due_centavos' => $invoice->balanceDue()->centavos(),
            // Per-row permissions so the list offers exactly the actions
            // that are legal for each document.
            //
            // State predicate FIRST, then the policy. The `Gate::before`
            // short-circuit returns true for every ability a platform admin
            // asks about, so asking the policy alone would put Edit and
            // Delete on issued documents.
            'can' => [
                'update' => $invoice->isMutable() && Gate::allows('update', $invoice),
                'delete' => $invoice->isMutable() && Gate::allows('delete', $invoice),
                'approve' => $invoice->isApprovable() && Gate::allows('approve', $invoice),
                'void' => $invoice->isVoidable() && Gate::allows('void', $invoice),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Invoice $invoice): array
    {
        return [
            ...$this->summarise($invoice),
            'is_vat_inclusive' => $invoice->is_vat_inclusive,
            'vatable_sales_centavos' => $invoice->vatable_sales_centavos,
            'vat_exempt_sales_centavos' => $invoice->vat_exempt_sales_centavos,
            'zero_rated_sales_centavos' => $invoice->zero_rated_sales_centavos,
            'vat_centavos' => $invoice->vat_centavos,
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'approved_at' => $invoice->approved_at?->toIso8601String(),
            // Both halves of the send record. Without them the page cannot
            // tell a sent invoice from an unsent one, and a re-send has no
            // address to default to.
            'sent_at' => $invoice->sent_at?->toIso8601String(),
            'sent_to' => $invoice->sent_to,
            'pay_url' => $this->payUrl($invoice),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'void_reason' => $invoice->void_reason,
            'contact' => $invoice->contact === null ? null : [
                'id' => $invoice->contact->id,
                'name' => $invoice->contact->name,
                'tin' => $invoice->contact->tin,
                'email' => $invoice->contact->email,
                'address' => $invoice->contact->address,
            ],
            'journal_entry' => $invoice->journalEntry === null ? null : [
                'id' => $invoice->journalEntry->id,
                'entry_number' => $invoice->journalEntry->entry_number,
                'status' => $invoice->journalEntry->status,
            ],
            'lines' => $invoice->lines->map(fn (InvoiceLine $line): array => [
                'id' => $line->id,
                'line_number' => $line->line_number,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price_centavos' => $line->unit_price_centavos,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'tax_rate_code' => $line->taxRate?->code,
                'tax_rate_label' => $line->taxRate?->ratePercentLabel(),
                'line_net_centavos' => $line->line_net_centavos,
                'line_tax_centavos' => $line->line_tax_centavos,
            ])->values(),
            'payments' => $invoice->allocations
                ->filter(fn (PaymentAllocation $allocation): bool => $allocation->payment?->isPosted() === true)
                ->map(fn (PaymentAllocation $allocation): array => [
                    'id' => $allocation->id,
                    'payment_id' => $allocation->payment_id,
                    'reference' => $allocation->payment?->reference,
                    'payment_date' => $allocation->payment?->payment_date?->toDateString(),
                    'amount_centavos' => $allocation->amount_centavos,
                ])
                ->values(),
            'can' => [
                ...$this->summarise($invoice)['can'],
                'print' => Gate::allows('print', $invoice),
                'send' => Gate::allows('send', $invoice),
            ],
        ];
    }

    /**
     * The customer-facing link, once one has been minted.
     *
     * Null until somebody presses Copy pay link — tokens are minted on demand
     * so the number of live public URLs stays equal to the number a person
     * deliberately created, and this only reports what already exists.
     *
     * It lives on the payload rather than being flashed back by `payLink()`
     * because a flash is a worse contract for it: `HandleFlashToasts` folds
     * every flash key into a `toast` prop, so the URL arrived as toast text
     * and the page's own read of `flash.payLink` found nothing. On the payload
     * the link is simply there after the mint, and can be shown as well as
     * copied.
     */
    private function payUrl(Invoice $invoice): ?string
    {
        $tenant = Tenant::current();

        if ($invoice->pay_token === null || $invoice->pay_token === '') {
            return null;
        }

        if (! $tenant instanceof School) {
            return null;
        }

        return route('public.pay.show', [
            'slug' => $tenant->slug,
            'token' => $invoice->pay_token,
        ]);
    }

    /**
     * Everything the create and edit forms need to populate their selects.
     *
     * @return array<string, mixed>
     */
    private function formOptions(string $type): array
    {
        $isSales = $type === Invoice::TYPE_SALES;

        return [
            'contactOptions' => Contact::query()
                ->active()
                ->when($isSales, fn ($q) => $q->where('is_customer', true))
                ->when(! $isSales, fn ($q) => $q->where('is_supplier', true))
                ->orderBy('name')
                ->get(['id', 'name', 'tin']),
            'accountOptions' => $this->accountOptions($isSales),
            // The counterparty picker can raise a contact without leaving the
            // draft, through the same sheet the register uses — so the same
            // control-account options travel with it. `ContactController@store`
            // redirects back here, and the new contact arrives in
            // `contactOptions` on the re-render.
            'canCreateContact' => Gate::allows('create', Contact::class),
            'receivableAccountOptions' => $this->controlAccountOptions(ChartOfAccount::TYPE_ASSET),
            'payableAccountOptions' => $this->controlAccountOptions(ChartOfAccount::TYPE_LIABILITY),
            // Dev/demo affordance only — super-admin outside production. The
            // form composes a random draft from the options above rather
            // than from a fixture, so what it fills is always real data for
            // this tenant and differs on every click.
            'canDemoFill' => Gate::allows('dev.demo-fill'),
            // Students with the contacts responsible for them, so choosing a
            // student can resolve a payer without a round trip. Sales only —
            // a supplier's bill has no pupil behind it.
            'studentOptions' => $isSales ? $this->studentOptions() : [],
            'taxRateOptions' => TaxRate::query()
                ->active()
                ->whereIn('type', $isSales
                    ? [TaxRate::TYPE_VAT_SALES, TaxRate::TYPE_EXEMPT, TaxRate::TYPE_ZERO_RATED]
                    : [TaxRate::TYPE_VAT_PURCHASE, TaxRate::TYPE_EXEMPT, TaxRate::TYPE_ZERO_RATED])
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'rate_bps', 'type']),
        ];
    }

    /**
     * Every student someone in this school is recorded as paying for.
     *
     * Built from `pas_contact_students` rather than from the LMS, because a
     * student nobody is linked to cannot be invoiced anyway — the payer would
     * fail validation. The primary payer is listed first so the form can take
     * the head of the list without re-deciding what "primary" means.
     *
     * @return list<array<string, mixed>>
     */
    private function studentOptions(): array
    {
        $links = ContactStudent::query()
            ->with('contact:id,name,tin,address')
            ->orderByDesc('is_primary_payer')
            ->orderBy('student_name')
            ->get();

        $byStudent = [];

        foreach ($links as $link) {
            $id = $link->lms_student_id;

            $byStudent[$id] ??= [
                'lms_student_id' => $id,
                'name' => $link->student_name,
                'payers' => [],
            ];

            $byStudent[$id]['payers'][] = [
                'contact_id' => $link->contact_id,
                'name' => $link->contact?->name,
                'tin' => $link->contact?->tin,
                'address' => $link->contact?->address,
                'relationship' => $link->relationship,
                'is_primary_payer' => $link->is_primary_payer,
            ];
        }

        return array_values($byStudent);
    }

    /**
     * Accounts offered as a control-account override on the new-contact sheet.
     *
     * Mirrors ContactController::accountOptions() — a receivable is an asset
     * and a payable is a liability, and offering the other side is always a
     * mistake. Duplicated rather than shared because the two controllers own
     * their own payloads; if a third caller appears, lift it to a service.
     *
     * @return Collection<int, ChartOfAccount>
     */
    private function controlAccountOptions(string $type): Collection
    {
        return ChartOfAccount::query()
            ->active()
            ->ofType($type)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);
    }

    /**
     * Accounts a line may post to.
     *
     * Income accounts for a sale, expense accounts for a purchase: those are
     * the only ones a document line can legitimately hit, and offering the
     * whole chart invites someone to credit a bank account directly and
     * silently break the receivable. Active only, for the same reason the
     * journal filters — an inactive account is one the school retired.
     *
     * @return Collection<int, ChartOfAccount>
     */
    private function accountOptions(bool $isSales): Collection
    {
        return ChartOfAccount::query()
            ->active()
            ->where('type', $isSales ? ChartOfAccount::TYPE_INCOME : ChartOfAccount::TYPE_EXPENSE)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'normal_balance']);
    }
}
