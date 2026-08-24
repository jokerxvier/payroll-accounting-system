<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Actions\Accounting\PostJournalEntry;
use App\Exceptions\ClosedAccountingPeriodException;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Services\Payroll\PayrollLineItem;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts an approved payroll run to the general ledger.
 *
 * This is the seam `rules/PLAN.md` §11 promised v1 would leave behind and
 * never did. It turns the run's payslips into one balanced journal entry:
 *
 *   DR  salaries and employer-contribution expense
 *     CR  statutory payables, loan and deduction liabilities
 *     CR  payroll clearing (net pay owed to employees)
 *
 * It balances by construction. Net pay is defined as gross minus employee
 * deductions, so:
 *
 *   debits  = earnings + employer contributions
 *   credits = employee deductions + employer contributions + net pay
 *           = employee deductions + employer contributions
 *             + (earnings - employee deductions)
 *           = earnings + employer contributions
 *
 * The identity holds per payslip and therefore over any set of them. It is
 * still asserted rather than assumed — {@see PostJournalEntry} refuses an
 * unbalanced entry, and a mismatch here would mean the engine's own totals
 * disagree with its line items, which is worth failing loudly over.
 *
 * Amounts are aggregated per account across the whole run rather than one
 * line per employee. A 500-employee run would otherwise produce thousands of
 * journal lines saying the same thing; the payslips remain the per-employee
 * record, and `posting_payload` freezes the breakdown that produced each
 * ledger line.
 */
final class LedgerPostingService
{
    public function __construct(
        private readonly PostJournalEntry $poster,
    ) {}

    /**
     * Post `$run` to the ledger and return the resulting journal entry.
     *
     * Idempotent: a run already linked to an entry returns that entry
     * untouched rather than posting a second one. Double-posting payroll
     * would double the expense and the liability, and it is exactly what a
     * retried job or a double-clicked button would cause.
     *
     * @throws DomainException Run is not posted, or has no payslips.
     * @throws UnbalancedJournalEntryException Engine totals disagree with its line items.
     * @throws ClosedAccountingPeriodException The run's period is closed.
     * @throws RuntimeException A mapped account is missing from the chart.
     */
    public function post(PayrollRun $run, int $actorUserId): JournalEntry
    {
        if ($run->journal_entry_id !== null) {
            $existing = JournalEntry::query()->find($run->journal_entry_id);

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($run->status !== PayrollRun::STATUS_POSTED) {
            throw new DomainException(sprintf(
                'Cannot post payroll run #%d to the ledger from status [%s]. Expected [posted].',
                $run->id,
                $run->status,
            ));
        }

        $payslips = $run->payslips()->get();

        if ($payslips->isEmpty()) {
            throw new DomainException(
                sprintf('Payroll run #%d has no payslips, so there is nothing to post.', $run->id)
            );
        }

        $buckets = $this->aggregate($payslips);

        return DB::transaction(function () use ($run, $actorUserId, $buckets): JournalEntry {
            $entry = JournalEntry::create([
                'date' => $this->postingDate($run),
                'reference' => sprintf('PAYROLL-%d', $run->id),
                'narration' => $this->narration($run),
                'status' => JournalEntry::STATUS_DRAFT,
                // The forward trace: a posted figure can always be walked
                // back to the run that produced it.
                'source_type' => PayrollRun::class,
                'source_id' => $run->id,
            ]);

            $lineNumber = 1;

            foreach ($buckets as $accountCode => $sides) {
                $net = $sides['debit'] - $sides['credit'];

                if ($net === 0) {
                    // A mapping where debits and credits cancel — e.g. an
                    // employer contribution whose expense and liability were
                    // pointed at the same account. Emitting a zero line
                    // would just be noise.
                    continue;
                }

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->getKey(),
                    'line_number' => $lineNumber++,
                    'account_id' => $this->accountIdForCode((string) $accountCode)->getKey(),
                    // Sign decides the side. Aggregating signed and splitting
                    // at the end means a net-negative expense lands as a
                    // credit instead of a negative debit, which the posting
                    // action would reject.
                    'debit_centavos' => $net > 0 ? $net : 0,
                    'credit_centavos' => $net < 0 ? -$net : 0,
                    'description' => sprintf('Payroll run #%d', $run->id),
                ]);
            }

            $posted = $this->poster->execute($entry->fresh(), $actorUserId);

            $run->forceFill([
                'journal_entry_id' => $posted->getKey(),
                'ledger_posted_at' => now(),
                'posting_payload' => $this->payload($run, $buckets, $posted),
            ])->save();

            return $posted;
        });
    }

    /**
     * Fold every payslip's line items into per-account debit/credit totals.
     *
     * @param  Collection<int, Payslip>  $payslips
     * @return array<string, array{debit: int, credit: int}>
     */
    private function aggregate(Collection $payslips): array
    {
        /** @var array<string, array{debit: int, credit: int}> $buckets */
        $buckets = [];

        $add = function (string $accountCode, string $side, int $centavos) use (&$buckets): void {
            $buckets[$accountCode] ??= ['debit' => 0, 'credit' => 0];
            $buckets[$accountCode][$side] += $centavos;
        };

        $netPayTotal = 0;

        foreach ($payslips as $payslip) {
            $netPayTotal += $payslip->net_pay_centavos;

            foreach ($payslip->hydratedAuditLines() as $item) {
                $centavos = $item->amount->centavos();

                if ($centavos === 0) {
                    continue;
                }

                match ($item->bucket) {
                    PayrollLineItem::BUCKET_EARNING => $add(
                        $this->mapped('earnings', $item->code),
                        'debit',
                        $centavos,
                    ),
                    PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION => $add(
                        $this->mapped('employee_deductions', $item->code),
                        'credit',
                        $centavos,
                    ),
                    // An employer contribution is both a cost and a debt, so
                    // it posts on both sides — self-balancing, which is why
                    // it cancels out of the identity in the class docblock.
                    PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION => (function () use ($add, $item, $centavos): void {
                        $pair = $this->mappedPair($item->code);
                        $add($pair['expense'], 'debit', $centavos);
                        $add($pair['liability'], 'credit', $centavos);
                    })(),
                    default => throw new DomainException(sprintf(
                        "Payroll line '%s' has unknown bucket '%s'; cannot decide which side of the ledger it belongs on.",
                        $item->code,
                        $item->bucket,
                    )),
                };
            }
        }

        if ($netPayTotal !== 0) {
            $add($this->netPayAccountCode(), 'credit', $netPayTotal);
        }

        return $buckets;
    }

    /**
     * Account code for a line code within a bucket, falling back to the
     * bucket default.
     *
     * Falling back rather than throwing is deliberate: dropping an unmapped
     * line would unbalance the entry, and a visibly-wrong account is far
     * easier to notice and correct than a silently missing amount.
     */
    private function mapped(string $bucket, string $code): string
    {
        /** @var array<string, string> $map */
        $map = (array) config("accounting.payroll.{$bucket}", []);

        return (string) ($map[$code] ?? $map['default'] ?? '');
    }

    /**
     * @return array{expense: string, liability: string}
     */
    private function mappedPair(string $code): array
    {
        /** @var array<string, array{expense: string, liability: string}> $map */
        $map = (array) config('accounting.payroll.employer_contributions', []);
        $pair = $map[$code] ?? $map['default'] ?? null;

        if (! is_array($pair) || ! isset($pair['expense'], $pair['liability'])) {
            throw new RuntimeException(sprintf(
                "Employer contribution '%s' has no expense/liability account pair configured, and there is no usable default in config/accounting.php.",
                $code,
            ));
        }

        return ['expense' => (string) $pair['expense'], 'liability' => (string) $pair['liability']];
    }

    private function netPayAccountCode(): string
    {
        $systemCode = (string) config('accounting.payroll.net_pay_system_account');

        $account = ChartOfAccount::query()
            ->where('system_code', $systemCode)
            ->first();

        if ($account === null) {
            throw new RuntimeException(sprintf(
                "This school has no '%s' system account. Run the accounting catalog seeder, or create the account before posting payroll to the ledger.",
                $systemCode,
            ));
        }

        return $account->code;
    }

    /**
     * Resolve a configured account code within the posting school.
     *
     * Throws rather than inventing an account: a missing mapping is a
     * configuration error the operator has to fix, and quietly posting into
     * a fallback would put real money somewhere nobody chose.
     */
    private function accountIdForCode(string $code): ChartOfAccount
    {
        $account = ChartOfAccount::query()->where('code', $code)->first();

        if ($account === null) {
            throw new RuntimeException(sprintf(
                "config/accounting.php maps a payroll component to account '%s', which does not exist in this school's chart of accounts.",
                $code,
            ));
        }

        return $account;
    }

    /**
     * The date the entry posts on.
     *
     * The pay period's end date, not today: the cost belongs to the period
     * the work was done in, which is also the period an accountant expects
     * to find it in when they close the month.
     */
    private function postingDate(PayrollRun $run): CarbonImmutable
    {
        $end = $run->payPeriod?->end_date;

        return $end !== null
            ? CarbonImmutable::parse($end->toDateString())
            : CarbonImmutable::now()->startOfDay();
    }

    private function narration(PayrollRun $run): string
    {
        $code = $run->payPeriod?->code;

        return $code !== null
            ? sprintf('Payroll for %s (run #%d)', $code, $run->id)
            : sprintf('Payroll run #%d', $run->id);
    }

    /**
     * The frozen breakdown stored on the run.
     *
     * @param  array<string, array{debit: int, credit: int}>  $buckets
     * @return array<string, mixed>
     */
    private function payload(PayrollRun $run, array $buckets, JournalEntry $entry): array
    {
        $lines = [];

        foreach ($buckets as $accountCode => $sides) {
            $net = $sides['debit'] - $sides['credit'];

            $lines[] = [
                'account_code' => (string) $accountCode,
                'debit_centavos' => $net > 0 ? $net : 0,
                'credit_centavos' => $net < 0 ? -$net : 0,
                'gross_debit_centavos' => $sides['debit'],
                'gross_credit_centavos' => $sides['credit'],
            ];
        }

        return [
            'payroll_run_id' => $run->id,
            'pay_period_code' => $run->payPeriod?->code,
            'journal_entry_id' => $entry->getKey(),
            'entry_number' => $entry->entry_number,
            'posted_at' => now()->toIso8601String(),
            'total_debit_centavos' => $entry->total_debit_centavos,
            'total_credit_centavos' => $entry->total_credit_centavos,
            'lines' => $lines,
        ];
    }

    /** Convenience for callers that only need to know a run reached the books. */
    public function hasPosted(PayrollRun $run): bool
    {
        return $run->journal_entry_id !== null;
    }
}
