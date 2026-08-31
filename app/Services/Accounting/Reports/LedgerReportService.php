<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Support\DayBoundary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The read side of the ledger — the one place the three ledger reports get
 * their figures.
 *
 * Four decisions apply to everything in here, and every report on top of it
 * inherits them:
 *
 * 1. **Posted entries only**, through {@see JournalEntry::scopePosted()}.
 *    Drafts have not moved the ledger. A reversed entry stays in scope
 *    deliberately: it and its reversal are both posted and cancel out, so a
 *    correction reads as two offsetting facts rather than as the original
 *    disappearing.
 *
 * 2. **Ranged on `pas_journal_entries.date`, never on `posted_at`.** An entry
 *    backdated into last month belongs in last month's figures no matter when
 *    somebody clicked post; ranging on `posted_at` would let a report change
 *    its answer for a closed period.
 *
 * 3. **Opening balance is every posted movement strictly before `from`.**
 *    It is derived here, never stored: there is no opening-balance column on
 *    an account, and nothing in this file knows a cutover from any other
 *    date. `from = null` means "since inception", and then the opening
 *    columns are zero by construction rather than by a special case.
 *
 *    Slice 9 added a cutover snapshot (`JournalEntry::SOURCE_OPENING_BALANCE`)
 *    so a school can carry in the balances it kept elsewhere — and this rule
 *    is exactly why that needed no change here. The snapshot is an ordinary
 *    posted entry dated at cutover, so it sweeps into the opening balance of
 *    every later range on its own. What this service still cannot say is
 *    where an opening figure came from; `CutoverNote` makes that distinction
 *    in the page, because it is a claim about provenance, not arithmetic.
 *
 * 4. **Debits and credits are summed separately and kept unsigned.** The
 *    normal-balance signing happens in the value objects, at the point a
 *    report actually needs a directional figure. Signing early is what makes
 *    a trial balance stop footing.
 */
final class LedgerReportService
{
    /**
     * Every account's opening balance, movement inside the range, and closing
     * balance.
     *
     * @param  bool  $includeInactive  Include accounts that are deactivated
     *                                 but were posted to while they were live.
     *                                 Dropping them would silently unbalance
     *                                 the report, so they are always included
     *                                 when they carry figures; this flag only
     *                                 governs empty ones.
     * @param  bool  $includeEmpty  Print the whole chart, including accounts
     *                              that have never been posted to.
     */
    public function trialBalance(
        ?CarbonImmutable $from,
        CarbonImmutable $to,
        bool $includeEmpty = false,
        bool $includeInactive = false,
    ): TrialBalance {
        $sums = $this->accountSums($from, $to);

        $accounts = ChartOfAccount::query()
            ->when(
                ! $includeInactive,
                // An inactive account that was posted to still has to appear,
                // or the columns stop footing. The filter therefore excludes
                // only inactive accounts with nothing on them.
                fn ($query) => $query->where(function ($inner) use ($sums): void {
                    $inner->where('is_active', true)
                        ->orWhereIn('id', $sums->keys()->all());
                }),
            )
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'normal_balance', 'is_active']);

        $rows = [];

        foreach ($accounts as $account) {
            $sum = $sums->get($account->getKey());

            $row = new TrialBalanceRow(
                accountId: (int) $account->getKey(),
                code: $account->code,
                name: $account->name,
                type: $account->type,
                normalBalance: $account->normal_balance,
                openingDebitCentavos: (int) ($sum->opening_debit ?? 0),
                openingCreditCentavos: (int) ($sum->opening_credit ?? 0),
                periodDebitCentavos: (int) ($sum->period_debit ?? 0),
                periodCreditCentavos: (int) ($sum->period_credit ?? 0),
            );

            if ($includeEmpty || $row->isSignificant()) {
                $rows[] = $row;
            }
        }

        return new TrialBalance($rows, $from, $to);
    }

    /**
     * One account's ledger: the balance it carried into the range, then every
     * posted line inside it in date order, each with a running balance.
     */
    public function accountLedger(
        ChartOfAccount $account,
        ?CarbonImmutable $from,
        CarbonImmutable $to,
    ): AccountLedger {
        $opening = $from === null
            ? 0
            : $this->rawBalanceBefore($account, $from);

        /** @var Collection<int, JournalEntryLine> $lines */
        $lines = JournalEntryLine::query()
            ->select('pas_journal_entry_lines.*')
            ->join(
                'pas_journal_entries',
                'pas_journal_entries.id',
                '=',
                'pas_journal_entry_lines.journal_entry_id',
            )
            ->where('pas_journal_entry_lines.account_id', $account->getKey())
            ->where('pas_journal_entries.status', JournalEntry::STATUS_POSTED)
            ->when($from !== null, fn ($query) => $query->where('pas_journal_entries.date', '>=', self::dayStart($from)))
            ->where('pas_journal_entries.date', '<=', self::dayEnd($to))
            // Date first so the running balance reads chronologically; then id,
            // because several entries can share a date and the running balance
            // has to be reproducible across runs.
            ->orderBy('pas_journal_entries.date')
            ->orderBy('pas_journal_entries.id')
            ->orderBy('pas_journal_entry_lines.line_number')
            ->with(['journalEntry:id,entry_number,date,reference,narration,reversal_of_entry_id'])
            ->get();

        $contra = $this->contraAccountsFor(
            $lines->pluck('journal_entry_id')->unique()->all(),
            (int) $account->getKey(),
        );

        $running = $opening;
        $ledgerLines = [];

        foreach ($lines as $line) {
            $running += $line->debit_centavos - $line->credit_centavos;
            $entry = $line->journalEntry;

            $ledgerLines[] = new AccountLedgerLine(
                lineId: (int) $line->getKey(),
                entryId: (int) $line->journal_entry_id,
                entryNumber: $entry->entry_number,
                date: $entry->date,
                reference: $entry->reference,
                narration: $entry->narration,
                description: $line->description,
                debitCentavos: $line->debit_centavos,
                creditCentavos: $line->credit_centavos,
                runningRawCentavos: $running,
                contraAccounts: $contra[$line->journal_entry_id] ?? [],
                isReversal: $entry->reversal_of_entry_id !== null,
            );
        }

        return new AccountLedger($account, $from, $to, $opening, $ledgerLines);
    }

    /**
     * Every posted entry in the range, in date order, with its lines and the
     * account each line hit.
     *
     * @return Collection<int, JournalEntry>
     */
    public function journal(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return JournalEntry::query()
            ->posted()
            ->whereBetween('date', [self::dayStart($from), self::dayEnd($to)])
            ->with(['lines.account:id,code,name,type'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Inclusive range boundaries as full timestamps.
     *
     * `pas_journal_entries.date` is a DATE column, but the two databases this
     * runs on disagree about what is in it. MySQL stores a real date and
     * compares by value; SQLite — which the test suite uses — stores whatever
     * Eloquent wrote, and Eloquent's `date` cast writes `Y-m-d H:i:s`. A
     * comparison against a bare `2026-08-31` is therefore a *string* compare
     * in SQLite, and `'2026-08-31 00:00:00' <= '2026-08-31'` is false: the
     * last day of every range silently vanishes.
     *
     * Comparing against full timestamps is correct on both. MySQL coerces the
     * string to a DATETIME and widens the DATE column to midnight; SQLite
     * compares like against like. Both keep the range a plain inequality, so
     * the `(school_id, date)` index is still usable — which `whereDate()`,
     * the other obvious fix, would have given up.
     */
    private static function dayStart(CarbonImmutable $date): string
    {
        return DayBoundary::start($date);
    }

    private static function dayEnd(CarbonImmutable $date): string
    {
        return DayBoundary::end($date);
    }

    /**
     * Raw (`debits − credits`) balance on one account across every posted
     * entry dated strictly before `$date`.
     */
    private function rawBalanceBefore(ChartOfAccount $account, CarbonImmutable $date): int
    {
        /** @var object{debit: ?int, credit: ?int} $totals */
        $totals = $this->postedLineQuery()
            ->where('pas_journal_entry_lines.account_id', $account->getKey())
            ->where('pas_journal_entries.date', '<', self::dayStart($date))
            ->selectRaw('COALESCE(SUM(pas_journal_entry_lines.debit_centavos), 0) AS debit')
            ->selectRaw('COALESCE(SUM(pas_journal_entry_lines.credit_centavos), 0) AS credit')
            ->first();

        return (int) $totals->debit - (int) $totals->credit;
    }

    /**
     * Per-account debit and credit sums, split into "before the range" and
     * "inside the range" in a single pass.
     *
     * One aggregate query for the whole report rather than four per account:
     * a chart of forty accounts would otherwise be a hundred and sixty
     * round trips.
     *
     * @return Collection<int, object>
     */
    private function accountSums(?CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $query = $this->postedLineQuery()
            ->where('pas_journal_entries.date', '<=', self::dayEnd($to))
            ->groupBy('pas_journal_entry_lines.account_id')
            ->select('pas_journal_entry_lines.account_id');

        if ($from === null) {
            // No opening split: the range is everything up to `$to`, so the
            // opening columns are structurally zero rather than filtered to
            // nothing.
            $query
                ->selectRaw('0 AS opening_debit')
                ->selectRaw('0 AS opening_credit')
                ->selectRaw('COALESCE(SUM(pas_journal_entry_lines.debit_centavos), 0) AS period_debit')
                ->selectRaw('COALESCE(SUM(pas_journal_entry_lines.credit_centavos), 0) AS period_credit');
        } else {
            $boundary = self::dayStart($from);

            $query
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN pas_journal_entries.date < ? THEN pas_journal_entry_lines.debit_centavos ELSE 0 END), 0) AS opening_debit',
                    [$boundary],
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN pas_journal_entries.date < ? THEN pas_journal_entry_lines.credit_centavos ELSE 0 END), 0) AS opening_credit',
                    [$boundary],
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN pas_journal_entries.date >= ? THEN pas_journal_entry_lines.debit_centavos ELSE 0 END), 0) AS period_debit',
                    [$boundary],
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN pas_journal_entries.date >= ? THEN pas_journal_entry_lines.credit_centavos ELSE 0 END), 0) AS period_credit',
                    [$boundary],
                );
        }

        return $query->get()->keyBy('account_id');
    }

    /**
     * The other accounts touched by each of `$entryIds`, excluding the
     * account being reported on.
     *
     * One query for the whole page. An entry that hits the same account on
     * both sides contributes nothing, which is correct — there is no other
     * side to name.
     *
     * @param  list<int>  $entryIds
     * @return array<int, list<string>>
     */
    private function contraAccountsFor(array $entryIds, int $excludeAccountId): array
    {
        if ($entryIds === []) {
            return [];
        }

        // Tenant-scoped through the model even though `$entryIds` already
        // came from a scoped query — a second filter costs nothing and this
        // is the join where an unscoped `DB::table()` would quietly read
        // another school's chart.

        $rows = JournalEntryLine::query()
            ->join(
                'pas_chart_of_accounts',
                'pas_chart_of_accounts.id',
                '=',
                'pas_journal_entry_lines.account_id',
            )
            ->whereIn('pas_journal_entry_lines.journal_entry_id', $entryIds)
            ->where('pas_journal_entry_lines.account_id', '!=', $excludeAccountId)
            ->orderBy('pas_chart_of_accounts.code')
            ->toBase()
            ->get([
                'pas_journal_entry_lines.journal_entry_id',
                'pas_chart_of_accounts.code',
                'pas_chart_of_accounts.name',
            ]);

        $grouped = [];

        foreach ($rows as $row) {
            $label = "{$row->code} {$row->name}";
            $entryId = (int) $row->journal_entry_id;

            // Two lines against the same account in one entry name it once.
            if (! in_array($label, $grouped[$entryId] ?? [], true)) {
                $grouped[$entryId][] = $label;
            }
        }

        return $grouped;
    }

    /**
     * Posted lines joined to their entries, tenant-scoped.
     *
     * The scope comes from {@see JournalEntryLine}'s `BelongsToTenant` global
     * scope, which qualifies its column, so it survives the join. Reaching
     * for `DB::table()` here instead would silently report across every
     * school in the database.
     */
    private function postedLineQuery(): QueryBuilder
    {
        return JournalEntryLine::query()
            ->join(
                'pas_journal_entries',
                'pas_journal_entries.id',
                '=',
                'pas_journal_entry_lines.journal_entry_id',
            )
            ->where('pas_journal_entries.status', JournalEntry::STATUS_POSTED)
            ->toBase();
    }
}
