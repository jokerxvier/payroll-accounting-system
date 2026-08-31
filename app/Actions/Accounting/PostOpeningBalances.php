<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\School;
use App\Services\Accounting\ControlAccountResolver;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 Slice 9 — the cutover snapshot.
 *
 * Writes ONE journal entry carrying every account's balance as at the day a
 * school's books were opened in this system, so the ledger no longer starts
 * at zero. It is an ordinary posted entry in every respect: it goes through
 * {@see PostJournalEntry} like everything else, which is what gets it the
 * balance assertion, the period lock, the number, and — because the reports
 * range on the entry's own `date` and not on `posted_at` — automatic
 * inclusion in the opening columns of every report run after the cutover.
 *
 * Three rules this action adds on top of the ordinary posting rules:
 *
 *   1. **Balance-sheet accounts only.** Assets, liabilities and equity carry
 *      balances across a year boundary; income and expenses do not. They
 *      close out to retained earnings, so an opening balance on one would
 *      report prior-year trading as if it were earned in the current period,
 *      and every Income Statement from then on would be overstated. Prior
 *      trading belongs in Retained Earnings as a single figure, which is
 *      exactly what the plug below is for.
 *
 *   2. **One snapshot per school.** A second snapshot would double every
 *      balance it touched. Correcting one means reversing it first, which is
 *      the house pattern for a posted entry and leaves both halves on the
 *      books to offset.
 *
 *   3. **The difference is never plugged silently.** A snapshot that does not
 *      balance means the source figures are wrong, and quietly routing the
 *      gap to Retained Earnings would hide that. The caller has to ask for
 *      the plug explicitly, having been shown the number first.
 */
final class PostOpeningBalances
{
    public function __construct(
        private readonly PostJournalEntry $poster,
        private readonly ControlAccountResolver $controlAccounts,
    ) {}

    /**
     * @param  list<array{account_id: int, debit_centavos: int, credit_centavos: int}>  $lines
     * @param  bool  $plugToRetainedEarnings  Route any difference to the
     *                                        school's RETAINED_EARNINGS
     *                                        account instead of refusing.
     *
     * @throws DomainException Snapshot already exists, empty, or an account
     *                         is unusable for an opening balance.
     */
    public function execute(
        CarbonImmutable $cutoverDate,
        array $lines,
        int $actorUserId,
        bool $plugToRetainedEarnings = false,
    ): JournalEntry {
        $this->assertNoLiveSnapshot();

        $lines = array_values(array_filter(
            $lines,
            static fn (array $l): bool => $l['debit_centavos'] !== 0 || $l['credit_centavos'] !== 0,
        ));

        if ($lines === []) {
            throw new DomainException(
                'An opening balance needs at least one account with a non-zero figure.'
            );
        }

        $accounts = $this->resolveAccounts($lines);

        return DB::transaction(function () use (
            $cutoverDate,
            $lines,
            $accounts,
            $actorUserId,
            $plugToRetainedEarnings,
        ): JournalEntry {
            if ($plugToRetainedEarnings) {
                $lines = $this->withRetainedEarningsPlug($lines);
            }

            $entry = JournalEntry::create([
                'date' => $cutoverDate,
                'reference' => 'OPENING',
                'narration' => sprintf(
                    'Opening balances as at %s',
                    $cutoverDate->toDateString(),
                ),
                'status' => JournalEntry::STATUS_DRAFT,
                'source_type' => JournalEntry::SOURCE_OPENING_BALANCE,
                'source_id' => null,
            ]);

            // Ordered by account code so the posted entry reads like the
            // chart it came from rather than like the spreadsheet's row
            // order, which is whatever the client happened to type.
            $ordered = $lines;
            usort(
                $ordered,
                static fn (array $a, array $b): int => strcmp(
                    $accounts[$a['account_id']]->code ?? '',
                    $accounts[$b['account_id']]->code ?? '',
                ),
            );

            $lineNumber = 0;
            foreach ($ordered as $line) {
                $lineNumber++;

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->getKey(),
                    'line_number' => $lineNumber,
                    'account_id' => $line['account_id'],
                    'debit_centavos' => $line['debit_centavos'],
                    'credit_centavos' => $line['credit_centavos'],
                    'description' => 'Opening balance',
                ]);
            }

            // refresh() rather than fresh(): same reload, but it returns $this
            // rather than a nullable clone, so the poster's non-null parameter
            // is satisfied without an assertion that could only ever be true.
            $posted = $this->poster->execute($entry->refresh(), $actorUserId);

            // Stamped from the entry that justifies it, inside the same
            // transaction, so the date a report cites and the entry it
            // describes can never disagree.
            School::query()
                ->whereKey($posted->school_id)
                ->update(['books_opened_on' => $cutoverDate->toDateString()]);

            return $posted;
        });
    }

    /**
     * A snapshot blocks another one only while it is standing.
     *
     * A reversed original is excluded (`reversed_at`), and so is the
     * reversing entry itself (`reversal_of_entry_id`) — {@see
     * ReverseJournalEntry} copies `source_type` onto the reversal so it
     * traces back to the same source, which means the sentinel alone
     * matches three rows for what is really one unwound snapshot.
     *
     * @throws DomainException
     */
    private function assertNoLiveSnapshot(): void
    {
        $existing = JournalEntry::query()
            ->openingBalance()
            ->posted()
            ->whereNull('reversed_at')
            ->whereNull('reversal_of_entry_id')
            ->first();

        if ($existing !== null) {
            throw new DomainException(sprintf(
                'Opening balances were already posted as %s dated %s. Reverse that entry before importing another snapshot.',
                $existing->entry_number,
                $existing->date->toDateString(),
            ));
        }
    }

    /**
     * @param  list<array{account_id: int, debit_centavos: int, credit_centavos: int}>  $lines
     * @return array<int, ChartOfAccount>
     *
     * @throws DomainException
     */
    private function resolveAccounts(array $lines): array
    {
        $ids = array_values(array_unique(array_column($lines, 'account_id')));

        /** @var EloquentCollection<int, ChartOfAccount> $accounts */
        $accounts = ChartOfAccount::query()->whereKey($ids)->get();

        /** @var array<int, ChartOfAccount> $keyed */
        $keyed = $accounts->keyBy('id')->all();

        foreach ($ids as $id) {
            $account = $keyed[$id] ?? null;

            // The tenant global scope means a foreign school's account
            // simply is not found, which is the refusal we want.
            if ($account === null) {
                throw new DomainException(
                    sprintf('Account [%d] does not exist in this school\'s chart of accounts.', $id)
                );
            }

            if (! $this->carriesAnOpeningBalance($account)) {
                throw new DomainException(sprintf(
                    'Account %s (%s) is an %s account and cannot carry an opening balance. Prior-year trading belongs in Retained Earnings.',
                    $account->code,
                    $account->name,
                    $account->type,
                ));
            }
        }

        return $keyed;
    }

    /**
     * Assets, liabilities and equity persist across a year boundary; income
     * and expenses are reset by the year-end close.
     */
    private function carriesAnOpeningBalance(ChartOfAccount $account): bool
    {
        return in_array($account->type, [
            ChartOfAccount::TYPE_ASSET,
            ChartOfAccount::TYPE_LIABILITY,
            ChartOfAccount::TYPE_EQUITY,
        ], true);
    }

    /**
     * Appends the balancing figure as a Retained Earnings line.
     *
     * The gap between a school's assets and its liabilities IS its accumulated
     * result to date, so Retained Earnings is where it belongs — not a
     * suspense account, which would only defer the same question.
     *
     * @param  list<array{account_id: int, debit_centavos: int, credit_centavos: int}>  $lines
     * @return list<array{account_id: int, debit_centavos: int, credit_centavos: int}>
     */
    private function withRetainedEarningsPlug(array $lines): array
    {
        $debits = Money::zero();
        $credits = Money::zero();

        foreach ($lines as $line) {
            $debits = $debits->plus(Money::fromCentavos($line['debit_centavos']));
            $credits = $credits->plus(Money::fromCentavos($line['credit_centavos']));
        }

        if ($debits->equals($credits)) {
            return $lines;
        }

        $retainedEarnings = $this->controlAccounts->systemAccount(
            ChartOfAccount::SYSTEM_RETAINED_EARNINGS,
        );

        $difference = $debits->greaterThan($credits)
            ? $debits->minus($credits)
            : $credits->minus($debits);

        $lines[] = [
            'account_id' => (int) $retainedEarnings->getKey(),
            // Debits exceeding credits needs a credit to close, and the
            // reverse for the other direction.
            'debit_centavos' => $debits->greaterThan($credits) ? 0 : $difference->centavos(),
            'credit_centavos' => $debits->greaterThan($credits) ? $difference->centavos() : 0,
        ];

        return $lines;
    }
}
