<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\Payment;
use App\Services\Accounting\InvoiceBalanceService;
use App\Support\DayBoundary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the school has billed, what has come in, and what is still owed.
 *
 * The read side of receivables — `rules/PLAN.md` §5 Slice 8c's foundation. The
 * Aged Receivables and Outstanding Invoices reports are the same figures with
 * a report's face on them, and should consume this rather than re-deriving.
 *
 * Four rules this depends on, each of which is a way to get the numbers wrong:
 *
 *  - **Aggregate off the invoice header.** `amount_paid_centavos` is written
 *    only by {@see InvoiceBalanceService}, which
 *    counts posted payments and nothing else, and every mutation path goes
 *    through it. Re-deriving from allocations here would be a correlated
 *    subquery per invoice for an answer already guaranteed to agree.
 *  - **Voided invoices keep a stale paid amount.** `recompute()` returns early
 *    on a void rather than zeroing it, so any money aggregate must go through
 *    `issued()` or `outstanding()`, which exclude voided and draft alike.
 *  - **Collections range on `payment_date`, not `posted_at`.** The business
 *    date, matching the ledger's own rule — a receipt backdated into August is
 *    August's collection however late it was keyed. It is also the only
 *    indexed choice; nothing indexes `posted_at`.
 *  - **Collections are gross.** `amount_centavos` is what the payer paid;
 *    `fee_centavos` is what a gateway kept. Reporting net would mean Collected
 *    plus Outstanding no longer reconciles to Invoiced, and a fully paid
 *    invoice would look short by the fee. The fee is an expense, and the
 *    accounting dashboard reports it as one.
 *
 * Credit notes do not exist (`rules/PLAN.md:419`, blocked on which BIR
 * documents a school may legally issue), so nothing here subtracts one. When
 * they arrive they reduce the same balance and every figure follows.
 */
final class ReceivablesService
{
    /** How many payers the outstanding table shows. */
    private const TOP_OUTSTANDING = 10;

    /** Ageing buckets, in days past due. `null` is the open-ended last one. */
    private const BUCKETS = [
        ['key' => 'current', 'label' => 'Current', 'from' => null, 'to' => 0],
        ['key' => '1_30', 'label' => '1–30 days', 'from' => 1, 'to' => 30],
        ['key' => '31_60', 'label' => '31–60 days', 'from' => 31, 'to' => 60],
        ['key' => '61_90', 'label' => '61–90 days', 'from' => 61, 'to' => 90],
        ['key' => 'over_90', 'label' => '90+ days', 'from' => 91, 'to' => null],
    ];

    public function forRange(
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $asOf,
    ): ReceivablesSummary {
        $outstanding = $this->outstandingInvoices();

        return new ReceivablesSummary(
            from: $from,
            to: $to,
            asOf: $asOf,
            invoicedCentavos: $this->invoicedBetween($from, $to),
            collectedCentavos: $this->collectedBetween($from, $to),
            outstandingCentavos: $this->sumRemaining($outstanding),
            overdueCentavos: $this->sumRemaining(
                $outstanding->filter(
                    fn (Invoice $invoice): bool => $this->daysOverdue($invoice, $asOf) > 0,
                ),
            ),
            aging: $this->aging($outstanding, $asOf),
            statuses: $this->statuses($asOf),
            monthly: $this->monthly($from, $to),
            topOutstanding: $this->topOutstanding($outstanding, $asOf),
        );
    }

    /**
     * Everything still owed, whenever it was billed.
     *
     * `scopeOutstanding()` is already documented on the model as "the basis of
     * AR/AP ageing" and is issued-and-underpaid: drafts and voids are out.
     * Loaded once and reused for the tiles, the buckets and the table so the
     * three cannot disagree — at school scale this is hundreds of rows, not
     * hundreds of thousands.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Invoice>
     */
    private function outstandingInvoices()
    {
        return Invoice::query()
            ->outstanding()
            ->ofType(Invoice::TYPE_SALES)
            ->with('contact:id,name')
            ->orderBy('due_date')
            ->get([
                'id', 'contact_id', 'student_name', 'number',
                'issue_date', 'due_date', 'total_centavos', 'amount_paid_centavos',
            ]);
    }

    /** @param Collection<int, Invoice> $invoices */
    private function sumRemaining($invoices): int
    {
        return (int) $invoices->sum(
            fn (Invoice $invoice): int => $invoice->total_centavos - $invoice->amount_paid_centavos,
        );
    }

    /** What was billed in the range, on the invoice's own issue date. */
    private function invoicedBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) Invoice::query()
            ->issued()
            ->ofType(Invoice::TYPE_SALES)
            ->where('issue_date', '>=', DayBoundary::start($from))
            ->where('issue_date', '<=', DayBoundary::end($to))
            ->sum('total_centavos');
    }

    /** What came in during the range — posted receipts, gross. */
    private function collectedBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) Payment::query()
            ->posted()
            ->where('type', Payment::TYPE_RECEIPT)
            ->where('payment_date', '>=', DayBoundary::start($from))
            ->where('payment_date', '<=', DayBoundary::end($to))
            ->sum('amount_centavos');
    }

    /**
     * How far past due an invoice is, or zero.
     *
     * **A null due date is never overdue.** The field is optional on the form
     * ("No due date"), so its absence means the school set no deadline —
     * treating that as instantly overdue would fill the Overdue tile with
     * invoices nobody ever agreed a date for.
     */
    private function daysOverdue(Invoice $invoice, CarbonImmutable $asOf): int
    {
        if ($invoice->due_date === null) {
            return 0;
        }

        $due = CarbonImmutable::parse($invoice->due_date->toDateString());

        return $due->greaterThanOrEqualTo($asOf) ? 0 : (int) $due->diffInDays($asOf);
    }

    /**
     * Outstanding money split by how long it has been owed.
     *
     * **The remainder, never the original total.** A ₱10,000 invoice with
     * ₱9,000 paid contributes ₱1,000 to its bucket; reporting the total would
     * overstate the school's exposure roughly tenfold on that row.
     *
     * @param  Collection<int, Invoice>  $outstanding
     * @return list<array{key: string, label: string, centavos: int}>
     */
    private function aging($outstanding, CarbonImmutable $asOf): array
    {
        $totals = [];

        foreach (self::BUCKETS as $bucket) {
            $totals[$bucket['key']] = 0;
        }

        foreach ($outstanding as $invoice) {
            $days = $this->daysOverdue($invoice, $asOf);
            $remaining = $invoice->total_centavos - $invoice->amount_paid_centavos;

            foreach (self::BUCKETS as $bucket) {
                $atOrAfter = $bucket['from'] === null || $days >= $bucket['from'];
                $atOrBefore = $bucket['to'] === null || $days <= $bucket['to'];

                if ($atOrAfter && $atOrBefore) {
                    $totals[$bucket['key']] += $remaining;

                    break;
                }
            }
        }

        return array_map(
            fn (array $bucket): array => [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'centavos' => $totals[$bucket['key']],
            ],
            self::BUCKETS,
        );
    }

    /**
     * Issued invoices by how they stand.
     *
     * **Overdue is a cut across the others, not a fifth kind.** An overdue
     * invoice is also unpaid or partially paid, so it is reported alongside
     * rather than as another slice of the same whole — a pie with all four
     * would count the same peso twice and total more than was billed.
     *
     * @return list<array{key: string, label: string, count: int, centavos: int}>
     */
    private function statuses(CarbonImmutable $asOf): array
    {
        $invoices = Invoice::query()
            ->issued()
            ->ofType(Invoice::TYPE_SALES)
            ->get(['id', 'due_date', 'total_centavos', 'amount_paid_centavos']);

        $slices = [
            'paid' => ['label' => 'Paid', 'count' => 0, 'centavos' => 0],
            'partially_paid' => ['label' => 'Partially paid', 'count' => 0, 'centavos' => 0],
            'unpaid' => ['label' => 'Unpaid', 'count' => 0, 'centavos' => 0],
            'overdue' => ['label' => 'Overdue', 'count' => 0, 'centavos' => 0],
        ];

        foreach ($invoices as $invoice) {
            $paid = $invoice->amount_paid_centavos;
            $total = $invoice->total_centavos;

            $key = match (true) {
                $paid >= $total && $total > 0 => 'paid',
                $paid > 0 => 'partially_paid',
                default => 'unpaid',
            };

            $slices[$key]['count']++;
            $slices[$key]['centavos'] += $total;

            if ($key !== 'paid' && $this->daysOverdue($invoice, $asOf) > 0) {
                $slices['overdue']['count']++;
                $slices['overdue']['centavos'] += $total - $paid;
            }
        }

        return array_map(
            fn (string $key): array => [
                'key' => $key,
                'label' => $slices[$key]['label'],
                'count' => $slices[$key]['count'],
                'centavos' => $slices[$key]['centavos'],
            ],
            array_keys($slices),
        );
    }

    /**
     * Billed against collected, month by month.
     *
     * Two independent aggregates, never joined: an invoice raised in August
     * and paid in September belongs to August's billing and September's
     * collections, and joining them would force it into one month or drop it.
     *
     * Dense, like the ledger series — a quiet month reads as missing data if
     * the chart simply skips it.
     *
     * @return list<array{month: string, label: string, invoiced_centavos: int, collected_centavos: int}>
     */
    private function monthly(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        $cursor = $from->startOfMonth();
        $last = $to->startOfMonth();
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($last) && $guard < 120) {
            $months[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'invoiced_centavos' => 0,
                'collected_centavos' => 0,
            ];

            $cursor = $cursor->addMonth();
            $guard++;
        }

        $invoices = Invoice::query()
            ->issued()
            ->ofType(Invoice::TYPE_SALES)
            ->where('issue_date', '>=', DayBoundary::start($from))
            ->where('issue_date', '<=', DayBoundary::end($to))
            ->get(['issue_date', 'total_centavos']);

        foreach ($invoices as $invoice) {
            $key = $invoice->issue_date->format('Y-m');

            if (isset($months[$key])) {
                $months[$key]['invoiced_centavos'] += $invoice->total_centavos;
            }
        }

        $payments = Payment::query()
            ->posted()
            ->where('type', Payment::TYPE_RECEIPT)
            ->where('payment_date', '>=', DayBoundary::start($from))
            ->where('payment_date', '<=', DayBoundary::end($to))
            ->get(['payment_date', 'amount_centavos']);

        foreach ($payments as $payment) {
            $key = $payment->payment_date->format('Y-m');

            if (isset($months[$key])) {
                $months[$key]['collected_centavos'] += $payment->amount_centavos;
            }
        }

        return array_values($months);
    }

    /**
     * Who owes the most.
     *
     * **Grouped by contact, not by student.** `pas_contact_students` exists so
     * that one family paying for three children is one payer with one balance;
     * listing them per child would show the same debt three times and rank a
     * large family above a genuinely delinquent one. The students are named
     * for context, from the snapshot already on each invoice — no join, and no
     * `resolveStudent()`, which is one cross-database query per row.
     *
     * @param  Collection<int, Invoice>  $outstanding
     * @return list<array{contact_id: int, contact_name: string, students: list<string>, invoiced_centavos: int, paid_centavos: int, outstanding_centavos: int, oldest_due_date: string|null, days_overdue: int, status: string}>
     */
    private function topOutstanding($outstanding, CarbonImmutable $asOf): array
    {
        /** @var array<int, list<Invoice>> $grouped */
        $grouped = [];

        foreach ($outstanding as $invoice) {
            $grouped[(int) $invoice->contact_id][] = $invoice;
        }

        $rows = [];

        foreach ($grouped as $contactId => $invoices) {
            $rows[] = $this->outstandingRow($contactId, $invoices, $asOf);
        }

        usort(
            $rows,
            fn (array $a, array $b): int => $b['outstanding_centavos'] <=> $a['outstanding_centavos'],
        );

        return array_slice($rows, 0, self::TOP_OUTSTANDING);
    }

    /**
     * One payer's line: what they owe, since when, and how late.
     *
     * @param  list<Invoice>  $invoices
     * @return array{contact_id: int, contact_name: string, students: list<string>, invoiced_centavos: int, paid_centavos: int, outstanding_centavos: int, oldest_due_date: string|null, days_overdue: int, status: string}
     */
    private function outstandingRow(int $contactId, array $invoices, CarbonImmutable $asOf): array
    {
        $invoiced = 0;
        $paid = 0;
        $daysOverdue = 0;
        $oldestDue = null;
        /** @var list<string> $students */
        $students = [];

        foreach ($invoices as $invoice) {
            $invoiced += $invoice->total_centavos;
            $paid += $invoice->amount_paid_centavos;
            $daysOverdue = max($daysOverdue, $this->daysOverdue($invoice, $asOf));

            $student = $invoice->student_name;

            if (is_string($student) && $student !== '' && ! in_array($student, $students, true)) {
                $students[] = $student;
            }

            $due = $invoice->due_date?->toDateString();

            if (is_string($due) && ($oldestDue === null || $due < $oldestDue)) {
                $oldestDue = $due;
            }
        }

        $contact = $invoices[0]->contact;

        return [
            'contact_id' => $contactId,
            'contact_name' => $contact === null ? 'Unknown payer' : $contact->name,
            'students' => $students,
            'invoiced_centavos' => $invoiced,
            'paid_centavos' => $paid,
            'outstanding_centavos' => $invoiced - $paid,
            'oldest_due_date' => $oldestDue,
            'days_overdue' => $daysOverdue,
            // Overdue outranks part-paid: a payer who has paid something but is
            // three months late is a collections problem, not a healthy one.
            'status' => match (true) {
                $daysOverdue > 0 => 'overdue',
                $paid > 0 => 'partially_paid',
                default => 'unpaid',
            },
        ];
    }

    /** Kept so a caller can resolve a payer without re-querying. */
    public function contactName(int $contactId): ?string
    {
        return Contact::query()->whereKey($contactId)->value('name');
    }
}
