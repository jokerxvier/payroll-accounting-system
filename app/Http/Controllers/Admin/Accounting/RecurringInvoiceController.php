<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Accounting\StartInvoiceSchedule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\RecurringInvoiceRequest;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\ContactStudent;
use App\Models\Pas\Invoice;
use App\Models\Pas\RecurringInvoice;
use App\Models\Pas\RecurringInvoiceLine;
use App\Models\Pas\TaxRate;
use App\Services\Accounting\RecurringInvoiceLineWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Standing instructions to bill the same payer on a cadence.
 *
 * A schedule is not a document — it produces them. So there is no approve, no
 * void, and no immutability: a schedule is edited freely, and editing it
 * changes only what it raises *next*. The invoices it has already produced are
 * documents and are corrected the way documents are, by voiding them.
 *
 * Pausing exists because stopping billing is a routine act — a family leaves,
 * a term ends — and deleting the schedule would release every period it has
 * claimed, which would let the generator bill those months all over again.
 *
 * **There is no create here.** A schedule is set up while raising the first
 * invoice, on the invoice form, by {@see StartInvoiceSchedule}
 * — the payer, the student and the lines have been typed once by then, and
 * asking for them again was how the two records came to disagree. It also let
 * a schedule be started for a month the operator had already billed by hand.
 * This controller manages schedules that exist: list, edit, pause, delete.
 */
final class RecurringInvoiceController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly RecurringInvoiceLineWriter $lines,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', RecurringInvoice::class);

        $status = $request->query('status');
        $status = in_array($status, ['active', 'paused'], true) ? $status : null;

        $schedules = RecurringInvoice::query()
            ->with(['contact:id,name'])
            ->withCount('periods')
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'paused', fn ($q) => $q->where('is_active', false))
            ->orderByDesc('is_active')
            ->orderBy('next_run_on')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (RecurringInvoice $s): array => $this->summarise($s));

        return Inertia::render('admin/accounting/recurring-invoices/index', [
            'schedules' => $schedules,
            'filters' => ['status' => $status],
            'can' => ['create' => Gate::allows('create', RecurringInvoice::class)],
        ]);
    }

    public function edit(RecurringInvoice $recurringInvoice): Response
    {
        Gate::authorize('update', $recurringInvoice);

        $recurringInvoice->load('lines');

        return Inertia::render('admin/accounting/recurring-invoices/edit', [
            'schedule' => [
                'id' => $recurringInvoice->id,
                'name' => $recurringInvoice->name,
                'type' => $recurringInvoice->type,
                'contact_id' => $recurringInvoice->contact_id,
                'lms_student_id' => $recurringInvoice->lms_student_id,
                'reference' => $recurringInvoice->reference,
                'is_vat_inclusive' => $recurringInvoice->is_vat_inclusive,
                'notes' => $recurringInvoice->notes,
                'terms' => $recurringInvoice->terms,
                'frequency' => $recurringInvoice->frequency,
                'day_of_month' => $recurringInvoice->day_of_month,
                'starts_on' => $recurringInvoice->starts_on->toDateString(),
                'ends_on' => $recurringInvoice->ends_on?->toDateString(),
                'due_days' => $recurringInvoice->due_days,
                'is_active' => $recurringInvoice->is_active,
                'lines' => $recurringInvoice->lines->map(fn (RecurringInvoiceLine $l): array => [
                    'description' => $l->description,
                    'quantity' => $l->quantity,
                    'unit_price_centavos' => $l->unit_price_centavos,
                    'account_id' => $l->account_id,
                    'tax_rate_id' => $l->tax_rate_id,
                ])->all(),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(
        RecurringInvoiceRequest $request,
        RecurringInvoice $recurringInvoice,
    ): RedirectResponse {
        Gate::authorize('update', $recurringInvoice);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        DB::transaction(function () use ($recurringInvoice, $data): void {
            // `next_run_on` is left alone on an edit. Recomputing it from the
            // new start date would re-open periods the schedule has already
            // billed, and the claims — not this column — are what stop that.
            $attributes = $this->attributes($data);
            unset($attributes['next_run_on']);

            $recurringInvoice->update($attributes);

            $this->lines->replace($recurringInvoice, (array) $data['lines']);
        });

        return redirect()
            ->route('admin.recurring-invoices.index')
            ->with('success', "{$recurringInvoice->name} updated. Invoices already raised are unchanged.");
    }

    public function destroy(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        Gate::authorize('delete', $recurringInvoice);

        $name = $recurringInvoice->name;

        $recurringInvoice->delete();

        return redirect()
            ->route('admin.recurring-invoices.index')
            ->with('success', "{$name} deleted. The invoices it raised are unaffected.");
    }

    /** Stop a schedule without losing the record of what it has billed. */
    public function pause(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        Gate::authorize('pause', $recurringInvoice);

        $recurringInvoice->forceFill(['is_active' => false])->save();

        return back()->with('success', "{$recurringInvoice->name} paused. It will raise nothing until you resume it.");
    }

    public function resume(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        Gate::authorize('pause', $recurringInvoice);

        $recurringInvoice->forceFill(['is_active' => true])->save();

        return back()->with('success', sprintf(
            '%s resumed. It will catch up any periods it missed, up to twelve.',
            $recurringInvoice->name,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $studentId = isset($data['lms_student_id']) ? (int) $data['lms_student_id'] : null;
        $startsOn = CarbonImmutable::parse((string) $data['starts_on']);

        return [
            'name' => (string) $data['name'],
            'type' => $data['type'],
            'contact_id' => (int) $data['contact_id'],
            'lms_student_id' => $studentId,
            // Snapshot, as on an invoice: what the schedule says must not
            // change because a name was corrected in the LMS later.
            'student_name' => $studentId === null
                ? null
                : ContactStudent::query()->forStudent($studentId)->value('student_name'),
            'reference' => $data['reference'] ?? null,
            'is_vat_inclusive' => (bool) $data['is_vat_inclusive'],
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
            'frequency' => $data['frequency'],
            'day_of_month' => (int) $data['day_of_month'],
            'starts_on' => $startsOn,
            'ends_on' => isset($data['ends_on']) ? CarbonImmutable::parse((string) $data['ends_on']) : null,
            'next_run_on' => $startsOn,
            'due_days' => isset($data['due_days']) ? (int) $data['due_days'] : null,
            'is_active' => (bool) $data['is_active'],
        ];
    }

    /** @return array<string, mixed> */
    private function summarise(RecurringInvoice $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'type' => $schedule->type,
            'contact_name' => $schedule->contact?->name,
            'student_name' => $schedule->student_name,
            'frequency' => $schedule->frequency,
            'day_of_month' => $schedule->day_of_month,
            'next_run_on' => $schedule->next_run_on->toDateString(),
            'ends_on' => $schedule->ends_on?->toDateString(),
            'is_active' => $schedule->is_active,
            'generated_count' => $schedule->generated_count,
            'last_generated_on' => $schedule->last_generated_on?->toDateString(),
            'last_error' => $schedule->last_error,
            'can' => [
                'update' => Gate::allows('update', $schedule),
                'delete' => Gate::allows('delete', $schedule),
                'pause' => Gate::allows('pause', $schedule),
            ],
        ];
    }

    /**
     * The same pickers the invoice form uses, minus the demo filler.
     *
     * Sales only: a schedule bills a family, and the student picker has no
     * meaning on a supplier's recurring bill. The type field still exists so a
     * recurring purchase can be added later without a migration.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'contactOptions' => Contact::query()
                ->active()
                ->where('is_customer', true)
                ->orderBy('name')
                ->get(['id', 'name', 'tin']),
            // Income accounts only: a schedule bills a family, so its lines
            // credit revenue. Same set the invoice form offers for a sale.
            'accountOptions' => ChartOfAccount::query()
                ->active()
                ->where('type', ChartOfAccount::TYPE_INCOME)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type', 'normal_balance']),
            'taxRateOptions' => TaxRate::query()
                ->active()
                ->whereIn('type', [TaxRate::TYPE_VAT_SALES, TaxRate::TYPE_EXEMPT, TaxRate::TYPE_ZERO_RATED])
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'rate_bps', 'type']),
        ];
    }
}
