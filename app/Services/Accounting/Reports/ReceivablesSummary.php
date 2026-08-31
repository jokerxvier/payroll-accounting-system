<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Carbon\CarbonImmutable;

/**
 * The invoice dashboard's figures — documents and payments, not the ledger.
 *
 * **This is the operational view, and it is allowed to disagree with the
 * accounting dashboard.** A draft invoice is real work that nobody has
 * approved; it is a thing an officer chases and it is not yet revenue. The two
 * dashboards answer different questions on purpose, and a figure here should
 * never be read as the school's position.
 *
 * Two kinds of number again, and the same trap. **Invoiced and Collected are
 * ranged** — what was billed and what came in between these dates. **Outstanding
 * and Overdue are as-at** — what is owed right now, whenever it was billed.
 * Ranging Outstanding would report "what was still unpaid out of this month's
 * billing", which is a different and much smaller number than what the school
 * is owed.
 *
 * @phpstan-type AgingBucket array{key: string, label: string, centavos: int}
 * @phpstan-type StatusSlice array{key: string, label: string, count: int, centavos: int}
 * @phpstan-type MonthPoint array{month: string, label: string, invoiced_centavos: int, collected_centavos: int}
 * @phpstan-type OutstandingRow array{contact_id: int, contact_name: string, students: list<string>, invoiced_centavos: int, paid_centavos: int, outstanding_centavos: int, oldest_due_date: ?string, days_overdue: int, status: string}
 */
final readonly class ReceivablesSummary
{
    /**
     * @param  list<AgingBucket>  $aging
     * @param  list<StatusSlice>  $statuses
     * @param  list<MonthPoint>  $monthly
     * @param  list<OutstandingRow>  $topOutstanding
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $asOf,
        public int $invoicedCentavos,
        public int $collectedCentavos,
        public int $outstandingCentavos,
        public int $overdueCentavos,
        public array $aging,
        public array $statuses,
        public array $monthly,
        public array $topOutstanding,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'as_of' => $this->asOf->toDateString(),
            'invoiced_centavos' => $this->invoicedCentavos,
            'collected_centavos' => $this->collectedCentavos,
            'outstanding_centavos' => $this->outstandingCentavos,
            'overdue_centavos' => $this->overdueCentavos,
            'aging' => $this->aging,
            'statuses' => $this->statuses,
            'monthly' => $this->monthly,
            'top_outstanding' => $this->topOutstanding,
        ];
    }
}
