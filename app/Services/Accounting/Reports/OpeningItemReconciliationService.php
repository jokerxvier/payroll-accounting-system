<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\School;
use Carbon\CarbonImmutable;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Does the sub-ledger tie to the control account it explains?
 *
 * The cutover snapshot states a receivable in total; the open items are the
 * documents behind it. Two independent routes to the same money, which is
 * exactly why they are worth comparing — a difference means the school's
 * previous system did not agree with itself, and that is a finding somebody
 * needs before they start chasing debts.
 *
 * **The control side is read as at the cutover date, not as at today.** By
 * today the snapshot's receivable has been drawn down by every payment since,
 * while the open items still list what was owed on day one; comparing those
 * two would report a difference that is simply the collections in between.
 *
 * The items side sums `total_centavos`, which for an open item IS the
 * balance brought forward — `RecordOpeningItems` nets off anything the school
 * collected before it moved, so the column holds what was owed at cutover and
 * never changes afterwards. Summing the live remainder instead would tie on
 * the day of the import and drift with every payment taken since, against a
 * control balance that stays fixed.
 */
final class OpeningItemReconciliationService
{
    public function __construct(
        private readonly LedgerReportService $ledger,
    ) {}

    /**
     * Both sides, or an empty list when the books were never opened.
     *
     * @param  array<int, array{type: string, total_centavos: int, amount_paid_centavos: int}>|null  $pending
     *                                                                                                         Parsed rows not yet recorded, so the preview can
     *                                                                                                         show the reconciliation BEFORE the user commits.
     *                                                                                                         Null reads what is already in the database.
     * @return list<OpeningItemReconciliation>
     */
    public function forCurrentSchool(?array $pending = null): array
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof School) {
            return [];
        }

        // Read from the row rather than the bound tenant instance, for the
        // reason spelled out in RecordOpeningItems: Spatie holds one School
        // object for the process and `books_opened_on` is stamped through the
        // query builder, so the instance can be stale.
        $openedOn = School::query()->whereKey($tenant->getKey())->value('books_opened_on');

        if ($openedOn === null) {
            return [];
        }

        $cutover = CarbonImmutable::parse(
            $openedOn instanceof \DateTimeInterface
                ? $openedOn->format('Y-m-d')
                : (string) $openedOn
        )->startOfDay();

        // One trial balance for both sides. `null` from-date makes every
        // column a closing balance as at the cutover, which is what the
        // snapshot contributed.
        $trialBalance = $this->ledger->trialBalance(null, $cutover);

        $rows = [];

        foreach ([
            ['receivable', 'Receivables', Invoice::TYPE_SALES, ChartOfAccount::SYSTEM_AR_CONTROL, 'receivable_account_id'],
            ['payable', 'Payables', Invoice::TYPE_PURCHASE, ChartOfAccount::SYSTEM_AP_CONTROL, 'payable_account_id'],
        ] as [$key, $label, $invoiceType, $systemCode, $overrideColumn]) {
            $rows[] = new OpeningItemReconciliation(
                key: $key,
                label: $label,
                controlCentavos: $this->controlBalance(
                    $trialBalance,
                    $this->controlAccountIds($systemCode, $overrideColumn),
                ),
                itemsCentavos: $pending === null
                    ? $this->recordedTotal($invoiceType)
                    : $this->pendingTotal($pending, $invoiceType),
            );
        }

        return $rows;
    }

    /**
     * @param  array<int, true>  $accountIds
     */
    private function controlBalance(TrialBalance $trialBalance, array $accountIds): int
    {
        $total = 0;

        foreach ($trialBalance->rows as $row) {
            if (isset($accountIds[$row->accountId])) {
                // Natural, not raw: a payable is credit-normal, and the raw
                // Dr−Cr figure would report every amount owed as negative.
                $total += $row->closingNaturalCentavos();
            }
        }

        return $total;
    }

    private function recordedTotal(string $invoiceType): int
    {
        return (int) Invoice::query()
            ->openingItems()
            ->ofType($invoiceType)
            ->sum('total_centavos');
    }

    /**
     * The same figure the import will record: gross less anything already
     * collected, so the preview states what the recorded sub-ledger will
     * actually come to rather than what the worksheet was typed as.
     *
     * @param  array<int, array{type: string, total_centavos: int, amount_paid_centavos: int}>  $pending
     */
    private function pendingTotal(array $pending, string $invoiceType): int
    {
        $total = 0;

        foreach ($pending as $item) {
            if ($item['type'] === $invoiceType) {
                $total += $item['total_centavos'] - $item['amount_paid_centavos'];
            }
        }

        return $total;
    }

    /**
     * The system control account plus every contact-level override.
     *
     * Mirrors `AccountingSummaryService::controlAccountIds()`. A contact with
     * its own receivable account still owes the school money, and leaving it
     * out would report a difference that is only a missing account.
     *
     * @return array<int, true>
     */
    private function controlAccountIds(string $systemCode, string $overrideColumn): array
    {
        $ids = ChartOfAccount::query()
            ->where('system_code', $systemCode)
            ->pluck('id')
            ->all();

        $overrides = Contact::query()
            ->whereNotNull($overrideColumn)
            ->distinct()
            ->pluck($overrideColumn)
            ->all();

        $all = [];

        foreach ([...$ids, ...$overrides] as $id) {
            $all[(int) $id] = true;
        }

        return $all;
    }
}
