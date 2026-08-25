<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Accounting\ApplyPaymentAllocations;
use App\Actions\Accounting\PostPayment;
use App\Actions\Accounting\VoidPayment;
use App\Exceptions\ClosedAccountingPeriodException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\PaymentRequest;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentAllocation;
use App\Services\Accounting\InvoiceBalanceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Admin surface for payments — Phase 5 Slice 7.
 *
 * Full pages rather than sheets, for the same reason invoices use them: the
 * allocation grid is a variable-length table of documents and amounts, and a
 * 640px panel makes the money columns unreadable.
 *
 * Allocation rules are enforced by {@see ApplyPaymentAllocations} rather than
 * here, so a `DomainException` from it comes back as a flash error. Every one
 * of those is something an operator can act on — an invoice already settled,
 * a counterparty mismatch, more allocated than the payment carries — and none
 * of them is a bug worth a 500.
 */
final class PaymentController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Payment::class);

        $type = (string) $request->query('type', Payment::TYPE_RECEIPT);
        $type = in_array($type, Payment::TYPES, true) ? $type : Payment::TYPE_RECEIPT;
        $status = (string) $request->query('status', '');

        $payments = Payment::query()
            ->with(['contact:id,name', 'cashAccount:id,code,name'])
            ->ofType($type)
            ->when(
                in_array($status, Payment::STATUSES, true),
                fn ($query) => $query->where('status', $status),
            )
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Payment $payment): array => $this->summarise($payment));

        return Inertia::render('admin/accounting/payments/index', [
            'payments' => $payments,
            'filters' => [
                'type' => $type,
                'status' => $status !== '' ? $status : null,
            ],
            'can' => [
                'create' => Gate::allows('create', Payment::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Payment::class);

        $type = (string) $request->query('type', Payment::TYPE_RECEIPT);
        $type = in_array($type, Payment::TYPES, true) ? $type : Payment::TYPE_RECEIPT;

        // The form re-requests this page with `only: ['outstandingInvoices']`
        // once a counterparty is chosen, rather than shipping every open
        // document in the school to every visitor.
        $contactId = $request->filled('contact_id')
            ? (int) $request->query('contact_id')
            : null;

        return Inertia::render('admin/accounting/payments/create', [
            'type' => $type,
            ...$this->formOptions($type, $contactId),
        ]);
    }

    public function store(PaymentRequest $request, ApplyPaymentAllocations $allocator): RedirectResponse
    {
        Gate::authorize('create', Payment::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        try {
            $payment = DB::transaction(function () use ($data, $allocator): Payment {
                $payment = Payment::create($this->attributes($data));

                $allocator->execute($payment, (array) $data['allocations']);

                return $payment;
            });
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.payments.show', $payment)
            ->with('success', 'Draft saved. Post it to record the money and settle the documents.');
    }

    public function show(Payment $payment): Response
    {
        Gate::authorize('view', $payment);

        $payment->load([
            'contact:id,name,tin,email',
            'cashAccount:id,code,name',
            'allocations.invoice:id,number,type,issue_date,total_centavos,amount_paid_centavos,status',
            'journalEntry:id,entry_number,status',
        ]);

        return Inertia::render('admin/accounting/payments/show', [
            'payment' => $this->detail($payment),
        ]);
    }

    public function edit(Request $request, Payment $payment): Response
    {
        Gate::authorize('update', $payment);
        $this->assertMutable($payment);

        $payment->load('allocations');

        // Same partial-reload seam as create(): switching the counterparty on
        // a draft has to reload the grid against the new one.
        $contactId = $request->filled('contact_id')
            ? (int) $request->query('contact_id')
            : $payment->contact_id;

        return Inertia::render('admin/accounting/payments/edit', [
            'payment' => [
                'id' => $payment->id,
                'type' => $payment->type,
                'contact_id' => $payment->contact_id,
                'payment_date' => $payment->payment_date->toDateString(),
                'amount_centavos' => $payment->amount_centavos,
                'cash_account_id' => $payment->cash_account_id,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'allocations' => $payment->allocations->map(
                    fn (PaymentAllocation $allocation): array => [
                        'invoice_id' => $allocation->invoice_id,
                        'amount_centavos' => $allocation->amount_centavos,
                    ],
                )->values(),
            ],
            ...$this->formOptions($payment->type, $contactId),
        ]);
    }

    public function update(
        PaymentRequest $request,
        Payment $payment,
        ApplyPaymentAllocations $allocator,
    ): RedirectResponse {
        Gate::authorize('update', $payment);
        $this->assertMutable($payment);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        try {
            DB::transaction(function () use ($payment, $data, $allocator): void {
                $payment->update($this->attributes($data));

                $allocator->execute($payment, (array) $data['allocations']);
            });
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.payments.show', $payment)
            ->with('success', 'Draft updated.');
    }

    public function destroy(Payment $payment, InvoiceBalanceService $balances): RedirectResponse
    {
        Gate::authorize('delete', $payment);
        $this->assertMutable($payment);

        $type = $payment->type;

        DB::transaction(function () use ($payment, $balances): void {
            // Recompute before the delete, while the allocations are still
            // readable. A draft's allocations count for nothing, so nothing
            // moves — but an invoice whose only allocation came from this
            // draft would otherwise never be revisited.
            $balances->recomputeFor($payment);

            $payment->delete();
        });

        return redirect()
            ->route('admin.payments.index', ['type' => $type])
            ->with('success', 'Draft deleted.');
    }

    public function post(Payment $payment, PostPayment $action): RedirectResponse
    {
        Gate::authorize('post', $payment);

        try {
            $posted = $action->execute($payment, (int) auth()->id());
        } catch (ClosedAccountingPeriodException|DomainException|RuntimeException $e) {
            return redirect()
                ->route('admin.payments.show', $payment)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.payments.show', $posted)
            ->with('success', 'Payment posted. The documents it settles have been updated.');
    }

    public function void(Request $request, Payment $payment, VoidPayment $action): RedirectResponse
    {
        Gate::authorize('void', $payment);

        $reversalDate = $request->filled('reversal_date')
            ? CarbonImmutable::parse((string) $request->input('reversal_date'))
            : null;

        try {
            $voided = $action->execute(
                $payment,
                (int) auth()->id(),
                (string) $request->input('reason', ''),
                $reversalDate,
            );
        } catch (ClosedAccountingPeriodException|DomainException $e) {
            return redirect()
                ->route('admin.payments.show', $payment)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.payments.show', $voided)
            ->with('success', 'Payment voided. The ledger entry has been reversed and the documents it settled now show as outstanding again.');
    }

    /**
     * Refuse to touch a payment that has already reached the ledger.
     *
     * `PaymentPolicy` folds this same predicate into `update` and `delete`,
     * but the policy alone is not enough: the `Gate::before` short-circuit in
     * `AppServiceProvider::registerPlatformAdminGate()` returns true for
     * every ability a platform admin asks about, sailing straight past the
     * state half of the check. Without this guard a platform admin could
     * re-key a posted payment and silently move both the cash and the
     * invoices it settled.
     */
    private function assertMutable(Payment $payment): void
    {
        abort_if(
            ! $payment->isMutable(),
            403,
            sprintf(
                'Payment #%d has been posted. Void it to reverse the ledger entry, then record a corrected one.',
                $payment->getKey(),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'type' => $data['type'],
            'contact_id' => (int) $data['contact_id'],
            'payment_date' => CarbonImmutable::parse((string) $data['payment_date']),
            'amount_centavos' => (int) $data['amount_centavos'],
            'cash_account_id' => (int) $data['cash_account_id'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => Payment::STATUS_DRAFT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'type' => $payment->type,
            'contact_name' => $payment->contact?->name,
            'payment_date' => $payment->payment_date->toDateString(),
            'amount_centavos' => $payment->amount_centavos,
            'allocated_centavos' => $payment->allocated_centavos,
            'unallocated_centavos' => $payment->unallocated()->centavos(),
            'method' => $payment->method,
            'reference' => $payment->reference,
            'cash_account_name' => $payment->cashAccount?->name,
            'status' => $payment->status,
            // Per-row permissions so the list offers exactly the actions that
            // are legal for each payment.
            //
            // State predicate FIRST, then the policy. `Gate::before` returns
            // true for every ability a platform admin asks about, so asking
            // the policy alone would put Edit and Delete on posted payments.
            'can' => [
                'update' => $payment->isMutable() && Gate::allows('update', $payment),
                'delete' => $payment->isMutable() && Gate::allows('delete', $payment),
                'post' => $payment->isPostable() && Gate::allows('post', $payment),
                'void' => $payment->isVoidable() && Gate::allows('void', $payment),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Payment $payment): array
    {
        return [
            ...$this->summarise($payment),
            'notes' => $payment->notes,
            'posted_at' => $payment->posted_at?->toIso8601String(),
            'voided_at' => $payment->voided_at?->toIso8601String(),
            'void_reason' => $payment->void_reason,
            'contact' => $payment->contact === null ? null : [
                'id' => $payment->contact->id,
                'name' => $payment->contact->name,
                'tin' => $payment->contact->tin,
                'email' => $payment->contact->email,
            ],
            'journal_entry' => $payment->journalEntry === null ? null : [
                'id' => $payment->journalEntry->id,
                'entry_number' => $payment->journalEntry->entry_number,
                'status' => $payment->journalEntry->status,
            ],
            'allocations' => $payment->allocations->map(
                fn (PaymentAllocation $allocation): array => [
                    'id' => $allocation->id,
                    'invoice_id' => $allocation->invoice_id,
                    'invoice_number' => $allocation->invoice?->number,
                    'invoice_status' => $allocation->invoice?->status,
                    'invoice_total_centavos' => $allocation->invoice?->total_centavos,
                    'amount_centavos' => $allocation->amount_centavos,
                ],
            )->values(),
        ];
    }

    /**
     * Everything the create and edit forms need.
     *
     * `outstandingInvoices` is scoped to one contact because the allocation
     * grid is only ever filled after a counterparty is chosen — loading every
     * open document in the school would be a large payload nobody looks at.
     * The create page therefore starts with an empty grid and the client
     * re-requests once a contact is picked.
     *
     * @return array<string, mixed>
     */
    private function formOptions(string $type, ?int $contactId = null): array
    {
        $isReceipt = $type === Payment::TYPE_RECEIPT;

        return [
            'contactOptions' => Contact::query()
                ->active()
                ->when($isReceipt, fn ($q) => $q->where('is_customer', true))
                ->when(! $isReceipt, fn ($q) => $q->where('is_supplier', true))
                ->orderBy('name')
                ->get(['id', 'name', 'tin']),
            'cashAccountOptions' => $this->cashAccountOptions(),
            'outstandingInvoices' => $contactId === null
                ? []
                : $this->outstandingInvoicesFor($type, $contactId),
        ];
    }

    /**
     * Documents this contact still owes on, oldest first.
     *
     * Oldest first because that is the order money is normally applied in,
     * and it is the order the "allocate oldest first" control works down.
     *
     * @return array<int, array<string, mixed>>
     */
    private function outstandingInvoicesFor(string $paymentType, int $contactId): array
    {
        $invoiceType = $paymentType === Payment::TYPE_RECEIPT
            ? Invoice::TYPE_SALES
            : Invoice::TYPE_PURCHASE;

        return Invoice::query()
            ->outstanding()
            ->ofType($invoiceType)
            ->where('contact_id', $contactId)
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get(['id', 'number', 'issue_date', 'due_date', 'total_centavos', 'amount_paid_centavos'])
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total_centavos' => $invoice->total_centavos,
                'amount_paid_centavos' => $invoice->amount_paid_centavos,
                'balance_due_centavos' => $invoice->balanceDue()->centavos(),
            ])
            ->values()
            ->all();
    }

    /**
     * Accounts money can move through.
     *
     * Reads the `is_cash_equivalent` flag added in Slice 8b. This used to
     * approximate cash as "any active asset with no `system_code`", which
     * excluded the control accounts — crediting Accounts Receivable directly
     * as if it were a bank account is exactly the mistake that makes a
     * receivable stop meaning anything — but still admitted Prepaid Expenses
     * and Property, Plant and Equipment. Neither is somewhere money sits.
     *
     * The `system_code` exclusion is kept as well as the flag, not replaced
     * by it: no system account is marked cash today, and if one ever were,
     * hand-posting to it would still be wrong.
     *
     * @return Collection<int, ChartOfAccount>
     */
    private function cashAccountOptions(): Collection
    {
        return ChartOfAccount::query()
            ->active()
            ->cashEquivalent()
            ->whereNull('system_code')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'normal_balance']);
    }
}
