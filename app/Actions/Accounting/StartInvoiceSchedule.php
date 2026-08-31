<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Pas\Invoice;
use App\Models\Pas\RecurringInvoice;
use App\Models\Pas\RecurringInvoicePeriod;
use App\Services\Accounting\RecurringInvoiceLineWriter;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Str;

/**
 * Turns an invoice someone just raised into a standing instruction to raise it again.
 *
 * The schedule is built from the document rather than from a second form. That
 * is the whole point of moving this onto the invoice page: the payer, the
 * student, the reference, the VAT basis and the lines have already been typed
 * once, and asking for them again is how the two records end up disagreeing.
 * Only the cadence is genuinely new.
 *
 * **The first period is claimed here, and that is not an optimisation.**
 * {@see GenerateDueInvoices} works out which period a schedule owes from the
 * number of rows in `pas_recurring_invoice_periods`, and nothing in it looks
 * for an existing invoice against the same payer and month. A schedule whose
 * `starts_on` is its own cadence day bills that day — so without this claim the
 * nightly run would raise a second invoice for the month the operator has just
 * billed by hand, and a family would be charged twice. Claiming period 0 makes
 * the manual invoice indistinguishable from one the generator raised, which is
 * exactly what it is.
 *
 * The caller owns the transaction, and it should be the invoice's own: the
 * draft and the instruction to repeat it are one decision, and half of it is
 * worse than neither.
 */
final class StartInvoiceSchedule
{
    public function __construct(
        private readonly RecurringInvoiceLineWriter $lines,
    ) {}

    /**
     * @param  array<string, mixed>  $recurrence  frequency, and optionally name and ends_on
     *
     * @throws DomainException The invoice is not one that can repeat.
     */
    public function execute(Invoice $invoice, array $recurrence): RecurringInvoice
    {
        if (! $invoice->isSales()) {
            // A bill is the supplier's own document. Repeating it would be
            // this school promising to receive something, which is not a
            // promise it is in a position to make.
            throw new DomainException('Only a sales invoice can be set to repeat.');
        }

        $invoice->loadMissing(['lines', 'contact']);

        $issueDate = CarbonImmutable::parse($invoice->issue_date->toDateString());

        $schedule = RecurringInvoice::create([
            'name' => $this->nameFor($invoice, $recurrence),
            'type' => $invoice->type,
            'contact_id' => $invoice->contact_id,
            'lms_student_id' => $invoice->lms_student_id,
            // Already snapshotted on the invoice, so it is copied rather than
            // looked up again — the two must agree about who was taught.
            'student_name' => $invoice->student_name,
            'reference' => $invoice->reference,
            'is_vat_inclusive' => $invoice->is_vat_inclusive,
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'frequency' => (string) $recurrence['frequency'],
            // Derived, never taken from the form. The invoice's own issue date
            // is the day this repeats on, so the two cannot disagree and there
            // is no way to ask for a 32nd.
            'day_of_month' => $issueDate->day,
            'starts_on' => $issueDate,
            'ends_on' => $this->endsOnFor($recurrence),
            // Seeded past the period the invoice covers. The claim below is
            // what actually guarantees it is not billed twice; this keeps the
            // schedule out of `scopeDueOn` until it has something to do, so
            // the nightly summary does not report it as skipped every night.
            'next_run_on' => $issueDate,
            'due_days' => $this->dueDaysFor($invoice, $issueDate),
            'is_active' => true,
        ]);

        $this->lines->replace($schedule, $this->linesFrom($invoice));

        $this->claimFirstPeriod($schedule, $invoice, $issueDate);

        $schedule->forceFill([
            'next_run_on' => $schedule->issueDateForPeriod(1),
        ])->save();

        return $schedule;
    }

    /**
     * When the schedule stops, or null to run until someone pauses it.
     *
     * @param  array<string, mixed>  $recurrence
     */
    private function endsOnFor(array $recurrence): ?CarbonImmutable
    {
        $endsOn = trim((string) ($recurrence['ends_on'] ?? ''));

        return $endsOn === '' ? null : CarbonImmutable::parse($endsOn);
    }

    /**
     * Record that the invoice just raised covers the schedule's first period.
     *
     * `invoice_id` is filled in immediately rather than left null: unlike the
     * generator, which claims before it knows whether the draft will build,
     * the document already exists here.
     */
    private function claimFirstPeriod(
        RecurringInvoice $schedule,
        Invoice $invoice,
        CarbonImmutable $issueDate,
    ): void {
        RecurringInvoicePeriod::create([
            'recurring_invoice_id' => $schedule->getKey(),
            'period' => $schedule->periodKeyFor($issueDate),
            'invoice_id' => $invoice->getKey(),
            'note' => 'Raised by hand when this schedule was set up.',
            'claimed_at' => now(),
        ]);
    }

    /**
     * How long the payer gets, taken from the invoice's own terms.
     *
     * An invoice carries two dates; a schedule cannot, because it has no
     * single issue date. The gap between them is the part that generalises.
     * Null when the invoice had no due date — that is "due on receipt", and it
     * repeats as such.
     */
    private function dueDaysFor(Invoice $invoice, CarbonImmutable $issueDate): ?int
    {
        if ($invoice->due_date === null) {
            return null;
        }

        $days = $issueDate->diffInDays(
            CarbonImmutable::parse($invoice->due_date->toDateString()),
        );

        return (int) max(0, $days);
    }

    /**
     * What the schedule is called in the register.
     *
     * The column is NOT NULL and the invoice form does not ask for a name —
     * the operator is raising a document, not naming a rule. So one is built
     * from what the document already says: the payer, and what it is for.
     *
     * @param  array<string, mixed>  $recurrence
     */
    private function nameFor(Invoice $invoice, array $recurrence): string
    {
        $given = trim((string) ($recurrence['name'] ?? ''));

        if ($given !== '') {
            return Str::limit($given, 120, '');
        }

        $contact = $invoice->contact;
        $payer = $contact === null ? 'Recurring invoice' : $contact->name;
        $first = $invoice->lines->first()?->description;

        return Str::limit(
            $first === null || $first === '' ? $payer : "{$payer} — {$first}",
            120,
            '',
        );
    }

    /**
     * The invoice's lines as a schedule's template lines.
     *
     * No net or tax: a template stores what to charge, not what it came to.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linesFrom(Invoice $invoice): array
    {
        return $invoice->lines
            ->map(fn ($line): array => [
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price_centavos' => $line->unit_price_centavos,
                'account_id' => $line->account_id,
                'tax_rate_id' => $line->tax_rate_id,
            ])
            ->values()
            ->all();
    }
}
