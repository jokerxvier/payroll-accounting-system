<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Accounting\ApproveInvoice;
use App\Actions\Accounting\VoidInvoice;
use App\Exceptions\ClosedAccountingPeriodException;
use App\Exceptions\DocumentNumberUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\InvoiceRequest;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\DocumentNumberSeries;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\TaxRate;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\InvoiceTotalsCalculator;
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

        $invoices = Invoice::query()
            ->with(['contact:id,name'])
            ->ofType($type)
            ->when(
                in_array($status, Invoice::STATUSES, true),
                fn ($query) => $query->where('status', $status),
            )
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Invoice $invoice): array => $this->summarise($invoice));

        return Inertia::render('admin/accounting/invoices/index', [
            'invoices' => $invoices,
            'filters' => [
                'type' => $type,
                'status' => $status !== '' ? $status : null,
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

    public function store(InvoiceRequest $request, InvoiceTotalsCalculator $calculator): RedirectResponse
    {
        Gate::authorize('create', Invoice::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $invoice = DB::transaction(function () use ($data, $calculator): Invoice {
            $invoice = Invoice::create($this->headerAttributes($data));

            $this->replaceLines($invoice, (array) $data['lines'], $calculator);

            return $invoice;
        });

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', 'Draft saved. Approve it to issue a number and post it to the ledger.');
    }

    public function show(Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load([
            'contact:id,name,tin,email,address',
            'lines.account:id,code,name',
            'lines.taxRate:id,code,name,rate_bps,type',
            'journalEntry:id,entry_number,status',
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
        InvoiceTotalsCalculator $calculator,
    ): RedirectResponse {
        Gate::authorize('update', $invoice);
        $this->assertMutable($invoice);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        DB::transaction(function () use ($invoice, $data, $calculator): void {
            $invoice->update($this->headerAttributes($data));

            $this->replaceLines($invoice, (array) $data['lines'], $calculator);
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

    public function approve(Invoice $invoice, ApproveInvoice $action): RedirectResponse
    {
        Gate::authorize('approve', $invoice);

        try {
            $approved = $action->execute($invoice, (int) auth()->id());
        } catch (DocumentNumberUnavailableException|ClosedAccountingPeriodException|DomainException|RuntimeException $e) {
            // Each of these is actionable: register a series, extend the
            // Authority To Print, reopen the period, fix the chart. None of
            // them is a bug worth a 500.
            return redirect()
                ->route('admin.invoices.show', $invoice)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.invoices.show', $approved)
            ->with('success', "{$approved->number} approved and posted to the ledger.");
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
    private function headerAttributes(array $data): array
    {
        return [
            'type' => $data['type'],
            'contact_id' => (int) $data['contact_id'],
            'reference' => $data['reference'] ?? null,
            'issue_date' => CarbonImmutable::parse((string) $data['issue_date']),
            'due_date' => isset($data['due_date']) && $data['due_date'] !== null
                ? CarbonImmutable::parse((string) $data['due_date'])
                : null,
            'is_vat_inclusive' => (bool) $data['is_vat_inclusive'],
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
            'status' => Invoice::STATUS_DRAFT,
        ];
    }

    /**
     * Replace a draft's lines wholesale, then recompute the totals.
     *
     * Deleting and re-inserting rather than diffing, for the same reason the
     * journal does: a draft's lines have no identity worth preserving, and
     * the audit trail reads better as "these lines were replaced" than as a
     * scatter of per-line edits. Deletion goes through Eloquent so each
     * removed line still writes its own audit row.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceLines(
        Invoice $invoice,
        array $lines,
        InvoiceTotalsCalculator $calculator,
    ): void {
        foreach ($invoice->lines()->get() as $existing) {
            $existing->delete();
        }

        $created = [];

        foreach (array_values($lines) as $index => $line) {
            $created[] = InvoiceLine::create([
                'invoice_id' => $invoice->getKey(),
                'line_number' => $index + 1,
                'description' => (string) $line['description'],
                'quantity' => number_format((float) $line['quantity'], 4, '.', ''),
                'unit_price_centavos' => (int) $line['unit_price_centavos'],
                'account_id' => (int) $line['account_id'],
                'tax_rate_id' => isset($line['tax_rate_id']) && $line['tax_rate_id'] !== null
                    ? (int) $line['tax_rate_id']
                    : null,
            ]);
        }

        // Load the rates the calculator needs in one query rather than one
        // per line.
        $models = InvoiceLine::query()
            ->with('taxRate')
            ->whereIn('id', array_map(static fn (InvoiceLine $l): int => $l->getKey(), $created))
            ->orderBy('line_number')
            ->get();

        $calculator->applyTo($invoice, $models);

        foreach ($models as $model) {
            $model->save();
        }

        $invoice->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'type' => $invoice->type,
            'number' => $invoice->number,
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
            'can' => [
                ...$this->summarise($invoice)['can'],
                'print' => Gate::allows('print', $invoice),
            ],
        ];
    }

    /**
     * Everything the create and edit forms need to populate their selects.
     *
     * `nextNumber` is a preview of the serial this document would take. It
     * is deliberately a peek and not an allocation — showing it must never
     * consume a number, because most drafts are opened and closed without
     * ever being approved.
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
            'taxRateOptions' => TaxRate::query()
                ->active()
                ->whereIn('type', $isSales
                    ? [TaxRate::TYPE_VAT_SALES, TaxRate::TYPE_EXEMPT, TaxRate::TYPE_ZERO_RATED]
                    : [TaxRate::TYPE_VAT_PURCHASE, TaxRate::TYPE_EXEMPT, TaxRate::TYPE_ZERO_RATED])
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'rate_bps', 'type']),
            'nextNumber' => app(DocumentNumberAllocator::class)->peek(
                $isSales
                    ? DocumentNumberSeries::TYPE_SALES_INVOICE
                    : DocumentNumberSeries::TYPE_BILL,
            ),
        ];
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
