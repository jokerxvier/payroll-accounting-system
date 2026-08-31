<?php

declare(strict_types=1);

namespace App\Imports;

use App\Actions\Accounting\PostOpeningBalances;
use App\Models\Pas\ChartOfAccount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Parses the cutover worksheet into per-row figures and per-row refusals.
 *
 * Writes nothing, exactly like {@see EmployeeBulkEditImport}: `parsed()`
 * returns a dataset the controller renders for preview, and only the confirm
 * endpoint — through {@see PostOpeningBalances} —
 * touches the ledger. Errors accumulate per row rather than aborting the
 * file, because someone reconciling an opening balance wants every problem
 * in one pass, not the first one.
 *
 * There is no diff here, and that is the difference from the employee
 * importer. A bulk edit changes existing rows and reports what moved; an
 * opening balance states figures for a ledger that has none, so every
 * non-zero row is new information and "changed from" has no meaning.
 */
final class OpeningBalanceImport implements ToCollection, WithHeadingRow
{
    use Importable;

    /**
     *Each row carries: `row_number`, `account_code`, `account_id`, `account_name`,
     * `account_type`, `debit_centavos`, `credit_centavos`, `errors`.
     *
     * Typed loosely because the per-row error helpers take the entry by
     * reference and append to it; a sealed array shape cannot survive that
     * and still describe what the helpers are allowed to do.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $parsed = [];

    /**
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        $this->parsed = [];

        // One lookup for the whole file rather than a query per row. Keyed
        // by code because the code is what a person transcribing from
        // another system has in front of them; ids are ours, not theirs.
        $accounts = ChartOfAccount::query()
            ->get()
            ->keyBy('code');

        $seenCodes = [];
        $rowNumber = 1; // heading is row 1; data starts at row 2

        foreach ($rows as $raw) {
            $rowNumber++;

            $entry = [
                'row_number' => $rowNumber,
                'account_code' => null,
                'account_id' => null,
                'account_name' => null,
                'account_type' => null,
                'debit_centavos' => 0,
                'credit_centavos' => 0,
                'errors' => [],
            ];

            $rawCode = $raw['account_code'] ?? null;

            // A wholly blank row is padding, not an error — spreadsheets
            // collect trailing empties and refusing them would fail files
            // that are perfectly correct.
            if ($this->isBlankRow($raw)) {
                continue;
            }

            if ($rawCode === null || trim((string) $rawCode) === '') {
                $entry['errors'][] = 'account_code is required.';
                $this->parsed[] = $entry;

                continue;
            }

            $code = trim((string) $rawCode);
            $entry['account_code'] = $code;

            $account = $accounts->get($code);

            if (! $account instanceof ChartOfAccount) {
                $entry['errors'][] = sprintf(
                    'No account with code %s exists in this school\'s chart of accounts.',
                    $code,
                );
                $this->parsed[] = $entry;

                continue;
            }

            $entry['account_id'] = (int) $account->getKey();
            $entry['account_name'] = $account->name;
            $entry['account_type'] = $account->type;

            if (isset($seenCodes[$code])) {
                $entry['errors'][] = sprintf(
                    'Account %s appears more than once (first on row %d). Combine the figures into one row.',
                    $code,
                    $seenCodes[$code],
                );
            } else {
                $seenCodes[$code] = $rowNumber;
            }

            if (! $account->is_active) {
                $entry['errors'][] = sprintf(
                    'Account %s is inactive and cannot carry an opening balance.',
                    $code,
                );
            }

            if (! in_array($account->type, [
                ChartOfAccount::TYPE_ASSET,
                ChartOfAccount::TYPE_LIABILITY,
                ChartOfAccount::TYPE_EQUITY,
            ], true)) {
                $entry['errors'][] = sprintf(
                    '%s is an %s account. Income and expense accounts close out at year end, so prior-year trading belongs in Retained Earnings rather than here.',
                    $code,
                    $account->type,
                );
            }

            $debit = $this->centavos($entry, 'opening_debit', $raw['opening_debit'] ?? null);
            $credit = $this->centavos($entry, 'opening_credit', $raw['opening_credit'] ?? null);

            if ($debit !== null && $credit !== null && $debit !== 0 && $credit !== 0) {
                $entry['errors'][] = 'A row carries either a debit or a credit, not both.';
            }

            $entry['debit_centavos'] = $debit ?? 0;
            $entry['credit_centavos'] = $credit ?? 0;

            $this->parsed[] = $entry;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parsed(): array
    {
        return $this->parsed;
    }

    /**
     * @param  array<string, mixed>|Collection<string, mixed>  $raw
     */
    private function isBlankRow(mixed $raw): bool
    {
        foreach (['account_code', 'opening_debit', 'opening_credit'] as $key) {
            $value = $raw[$key] ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Decimal pesos to integer centavos.
     *
     * Commas and spaces are stripped before parsing: a client copying a
     * trial balance out of another system brings "1,234.56" with it, and
     * rejecting thousands separators would fail files whose figures are
     * perfectly correct. Nothing else is inferred — a stray currency symbol
     * or a parenthesised negative is reported rather than guessed at.
     *
     * @param  array<string, mixed>  $entry
     * @return int|null Null when the cell could not be read, which is
     *                  distinct from a legitimate zero.
     */
    private function centavos(array &$entry, string $field, mixed $rawValue): ?int
    {
        if ($rawValue === null || trim((string) $rawValue) === '') {
            return 0; // blank cell = nothing on this side
        }

        $value = str_replace([',', ' '], '', trim((string) $rawValue));

        if (! is_numeric($value)) {
            $entry['errors'][] = sprintf('%s must be a number.', $field);

            return null;
        }

        // bccomp rather than a float cast: this file is nothing but money,
        // and the module's rule is that no monetary value becomes a float at
        // any point, comparison included.
        if (bccomp($value, '0', 2) < 0) {
            $entry['errors'][] = sprintf(
                '%s cannot be negative. Put the figure in the other column instead of negating it.',
                $field,
            );

            return null;
        }

        // bcmul rather than (int) round($v * 100): the float round-trip is
        // what turns 8.35 into 834 centavos, and this file is nothing but
        // money.
        return (int) bcmul($value, '100', 0);
    }
}
