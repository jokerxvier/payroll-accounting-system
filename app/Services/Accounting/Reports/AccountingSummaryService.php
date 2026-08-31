<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use Carbon\CarbonImmutable;

/**
 * The accounting dashboard's tiles and revenue breakdown.
 *
 * Classifies what {@see LedgerReportService::trialBalance()} already returns
 * rather than writing new SQL. `rules/PLAN.md` §5 frames the financial
 * statements as "classifies and subtotals what 8a returns", and that is
 * literally this: one aggregate query for every account's opening, period and
 * closing figures — tenant-scoped, posted-only, correctly day-bounded — then
 * arithmetic over the rows. Everything the ledger reports guarantee, these
 * figures inherit for free, including the tenancy scope that makes reports the
 * widest such surface in the codebase.
 *
 * **Period versus closing is the whole job.** Income and expenses are period
 * movements; cash, receivables and payables are closing balances. See
 * {@see AccountingSummary} for why conflating them is the classic failure.
 *
 * Called once per page load: `forRange()` makes a single `trialBalance()` call
 * and derives all six tiles plus the revenue breakdown from the same rows, so
 * the tiles cannot disagree with the chart beneath them.
 */
final class AccountingSummaryService
{
    public function __construct(
        private readonly LedgerReportService $ledger,
    ) {}

    public function forRange(?CarbonImmutable $from, CarbonImmutable $to): AccountingSummary
    {
        $rows = $this->ledger->trialBalance($from, $to)->rows;

        $cashAccountIds = $this->cashAccountIds();
        $receivableIds = $this->receivableAccountIds();
        $payableIds = $this->payableAccountIds();

        $cash = 0;
        $receivables = 0;
        $payables = 0;
        $income = 0;
        $expenses = 0;
        $revenue = [];

        foreach ($rows as $row) {
            // Balances: as at the end of the range, opening included.
            if (isset($cashAccountIds[$row->accountId])) {
                $cash += $row->closingNaturalCentavos();
            }

            if (isset($receivableIds[$row->accountId])) {
                $receivables += $row->closingNaturalCentavos();
            }

            if (isset($payableIds[$row->accountId])) {
                $payables += $row->closingNaturalCentavos();
            }

            // Movements: what happened inside the range.
            if ($row->type === ChartOfAccount::TYPE_INCOME) {
                $earned = $row->periodNaturalCentavos();
                $income += $earned;

                if ($earned !== 0) {
                    $revenue[] = [
                        'account_id' => $row->accountId,
                        'code' => $row->code,
                        'name' => $row->name,
                        'centavos' => $earned,
                    ];
                }
            }

            if ($row->type === ChartOfAccount::TYPE_EXPENSE) {
                $expenses += $row->periodNaturalCentavos();
            }
        }

        // Largest first: the chart reads as a ranking, and a school with
        // twenty fee accounts wants tuition at the top, not account 4100.
        usort($revenue, fn (array $a, array $b): int => $b['centavos'] <=> $a['centavos']);

        return new AccountingSummary(
            from: $from,
            to: $to,
            cashCentavos: $cash,
            receivablesCentavos: $receivables,
            payablesCentavos: $payables,
            incomeCentavos: $income,
            expensesCentavos: $expenses,
            revenueByAccount: $revenue,
        );
    }

    /**
     * Accounts that are part of the cash balance.
     *
     * `is_cash_equivalent` rather than "type is asset": the flag exists
     * precisely because `cash_flow_category` says which section an account's
     * movements belong to, not whether the account IS cash. Inactive ones are
     * included — a retired bank account with a balance still holds money.
     *
     * @return array<int, true>
     */
    private function cashAccountIds(): array
    {
        $ids = [];

        foreach (ChartOfAccount::query()->cashEquivalent()->pluck('id') as $id) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }

    /**
     * Every account a receivable can sit in.
     *
     * Not just `SYSTEM_AR_CONTROL`. `ControlAccountResolver` lets a contact
     * override its receivable account, so a school that gives one payer its
     * own control account would have that balance missing from a figure built
     * on the system code alone — and the tile would quietly understate what
     * the school is owed.
     *
     * @return array<int, true>
     */
    private function receivableAccountIds(): array
    {
        return $this->controlAccountIds(
            ChartOfAccount::SYSTEM_AR_CONTROL,
            'receivable_account_id',
        );
    }

    /** @return array<int, true> */
    private function payableAccountIds(): array
    {
        return $this->controlAccountIds(
            ChartOfAccount::SYSTEM_AP_CONTROL,
            'payable_account_id',
        );
    }

    /**
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
