<?php

declare(strict_types=1);

namespace App\Imports;

use App\Http\Requests\Admin\Accounting\ChartOfAccountUpdateRequest;
use App\Models\Pas\ChartOfAccount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Parses the chart worksheet into per-row values, changes and refusals.
 *
 * Writes nothing; the confirm endpoint applies what this returns. `code` is
 * the join key, so an existing code updates that account and a new one creates
 * it — the same contract as the contact importer, and the same sharp edge:
 * editing a code does not renumber an account, it makes a second one.
 *
 * **The chart is the one register where a bad bulk edit is not recoverable by
 * re-uploading.** Every posted journal line points at an account id, so
 * flipping an account's type re-signs figures already reported. Three columns
 * are therefore read on export and refused on import — `normal_balance`
 * (derived from type), `system_code`, `is_locked` — and a locked account
 * accepts no change to its code or type at all, mirroring
 * {@see ChartOfAccountUpdateRequest}.
 *
 * Parents are resolved in a second pass, so a sheet may nest an account under
 * one defined further down. That pass is also where cycles are caught: a chart
 * containing A→B→A renders as accounts that silently vanish from the tree, and
 * a bulk import is exactly where one gets introduced.
 */
final class ChartOfAccountImport implements ToCollection, WithHeadingRow
{
    /**
     * Each row carries: `row_number`, `code`, `account_id`, `name`, `action`
     * (`create` | `update` | `unchanged`), `parent_code`, `attributes`,
     * `changes`, `errors`.
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

        $existing = ChartOfAccount::query()->get()->keyBy(
            fn (ChartOfAccount $account): string => mb_strtolower(trim($account->code)),
        );

        $seenCodes = [];
        $rowNumber = 1; // heading is row 1; data starts at row 2

        foreach ($rows as $raw) {
            $rowNumber++;

            if ($this->isBlankRow($raw)) {
                continue;
            }

            $entry = [
                'row_number' => $rowNumber,
                'code' => null,
                'account_id' => null,
                'name' => null,
                'action' => 'create',
                'parent_code' => null,
                'attributes' => [],
                'changes' => [],
                'errors' => [],
            ];

            $code = trim((string) ($raw['code_do_not_change'] ?? $raw['code'] ?? ''));

            if ($code === '' || mb_strlen($code) > 20) {
                $entry['errors'][] = $code === ''
                    ? 'code is required. It is what an update is matched on.'
                    : 'code is longer than 20 characters.';
                $this->parsed[] = $entry;

                continue;
            }

            $entry['code'] = $code;
            $key = mb_strtolower($code);

            if (isset($seenCodes[$key])) {
                $entry['errors'][] = sprintf(
                    'Code %s appears more than once (first on row %d).',
                    $code,
                    $seenCodes[$key],
                );
                $this->parsed[] = $entry;

                continue;
            }

            $seenCodes[$key] = $rowNumber;

            $account = $existing->get($key);
            $entry['account_id'] = $account?->getKey();
            $entry['action'] = $account === null ? 'create' : 'update';

            $this->readName($entry, $raw['name'] ?? null, $account);
            $this->readType($entry, $raw['type'] ?? null, $account);
            $this->readCashFlow($entry, $raw['cash_flow_category'] ?? null, $account);
            $this->readFlags($entry, $raw, $account);
            $this->readText($entry, 'subtype', $raw['subtype'] ?? null, 40, $account);
            $this->readText($entry, 'description', $raw['description'] ?? null, 2000, $account);

            // Kept as a code and resolved once every row is known, so a parent
            // defined lower down the sheet still works.
            $parentCode = trim((string) ($raw['parent_code'] ?? ''));
            $entry['parent_code'] = $parentCode === '' ? null : $parentCode;

            $this->guardLocked($entry, $account);
            $this->guardCashEquivalentIsAnAsset($entry, $account);

            $this->parsed[] = $entry;
        }

        $this->resolveParents($existing, $seenCodes);
        $this->markUnchanged();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parsed(): array
    {
        return $this->parsed;
    }

    /** @param array<string, mixed> $entry */
    private function readName(array &$entry, mixed $raw, ?ChartOfAccount $account): void
    {
        $name = trim((string) ($raw ?? ''));

        if ($name === '') {
            $entry['errors'][] = 'name is required.';

            return;
        }

        if (mb_strlen($name) > 160) {
            $entry['errors'][] = 'name is longer than 160 characters.';

            return;
        }

        $entry['name'] = $name;
        $this->set($entry, 'name', $name, $account?->name);
    }

    /**
     * The type, and the normal balance it implies.
     *
     * `normal_balance` is written here rather than read from the sheet — one
     * source of truth, the same one the form uses. A chart that could state
     * both would let the two disagree, and the ledger would sign every figure
     * on that account by the wrong one.
     *
     * @param  array<string, mixed>  $entry
     */
    private function readType(array &$entry, mixed $raw, ?ChartOfAccount $account): void
    {
        $type = mb_strtolower(trim((string) ($raw ?? '')));

        // "revenue" is what the interface calls `income` everywhere, so a
        // person filling in the sheet from the screen will write it.
        if ($type === 'revenue') {
            $type = ChartOfAccount::TYPE_INCOME;
        }

        if ($type === '') {
            $entry['errors'][] = 'type is required.';

            return;
        }

        if (! in_array($type, ChartOfAccount::TYPES, true)) {
            $entry['errors'][] = sprintf(
                '"%s" is not an account type. Use one of: %s.',
                $type,
                implode(', ', ChartOfAccount::TYPES),
            );

            return;
        }

        $this->set($entry, 'type', $type, $account?->type);
        $this->set(
            $entry,
            'normal_balance',
            ChartOfAccount::normalBalanceForType($type),
            $account?->normal_balance,
        );
    }

    /** @param array<string, mixed> $entry */
    private function readCashFlow(array &$entry, mixed $raw, ?ChartOfAccount $account): void
    {
        $value = mb_strtolower(trim((string) ($raw ?? '')));

        if ($value === '') {
            // Blank keeps what is stored on an update, and takes the column
            // default on a create.
            $this->set(
                $entry,
                'cash_flow_category',
                $account === null
                    ? ChartOfAccount::CASH_FLOW_NONE
                    : $account->cash_flow_category,
                $account?->cash_flow_category,
            );

            return;
        }

        if (! in_array($value, ChartOfAccount::CASH_FLOW_CATEGORIES, true)) {
            $entry['errors'][] = sprintf(
                '"%s" is not a cash flow category. Use one of: %s.',
                $value,
                implode(', ', ChartOfAccount::CASH_FLOW_CATEGORIES),
            );

            return;
        }

        $this->set($entry, 'cash_flow_category', $value, $account?->cash_flow_category);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>|Collection<string, mixed>  $raw
     */
    private function readFlags(array &$entry, mixed $raw, ?ChartOfAccount $account): void
    {
        foreach (['is_cash_equivalent', 'is_active'] as $field) {
            $text = mb_strtolower(trim((string) ($raw[$field] ?? '')));

            if ($text === '') {
                if ($account === null) {
                    // A new account is active and is not cash unless said.
                    $this->set($entry, $field, $field === 'is_active', null);
                }

                continue;
            }

            $truthy = in_array($text, ['yes', 'y', 'true', '1'], true);
            $falsy = in_array($text, ['no', 'n', 'false', '0'], true);

            if (! $truthy && ! $falsy) {
                $entry['errors'][] = sprintf('%s must be yes or no.', $field);

                continue;
            }

            $this->set($entry, $field, $truthy, $account?->{$field});
        }
    }

    /** @param array<string, mixed> $entry */
    private function readText(
        array &$entry,
        string $field,
        mixed $raw,
        int $max,
        ?ChartOfAccount $account,
    ): void {
        $text = trim((string) ($raw ?? ''));

        if ($text === '') {
            $this->set($entry, $field, null, $account?->{$field});

            return;
        }

        if (mb_strlen($text) > $max) {
            $entry['errors'][] = sprintf('%s is longer than %d characters.', $field, $max);

            return;
        }

        $this->set($entry, $field, $text, $account?->{$field});
    }

    /**
     * A locked account keeps its code and its type, whatever the sheet says.
     *
     * Same two refusals the edit form makes, for the same reasons: the code is
     * cited by posted entries, and the type decides how every figure already
     * on the account is signed.
     *
     * @param  array<string, mixed>  $entry
     */
    private function guardLocked(array &$entry, ?ChartOfAccount $account): void
    {
        if ($account === null || ! $account->is_locked) {
            return;
        }

        $newType = $entry['attributes']['type'] ?? null;

        if ($newType !== null && $newType !== $account->type) {
            $entry['errors'][] = sprintf(
                '%s is a system account. Changing its type would invert the normal balance of entries already posted to it.',
                $account->code,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function guardCashEquivalentIsAnAsset(array &$entry, ?ChartOfAccount $account): void
    {
        $isCash = $entry['attributes']['is_cash_equivalent']
            ?? ($account === null ? false : $account->is_cash_equivalent);
        $type = $entry['attributes']['type'] ?? $account?->type;

        if ($isCash === true && $type !== null && $type !== ChartOfAccount::TYPE_ASSET) {
            $entry['errors'][] = 'Only an asset account can be a cash equivalent.';
        }
    }

    /**
     * Second pass: turn every `parent_code` into an id, and refuse cycles.
     *
     * Deferred to here so a row may name a parent defined further down the
     * sheet — a chart is usually typed in code order, and a person adding a
     * sub-account should not have to reorder the file.
     *
     * @param  Collection<string, ChartOfAccount>  $existing
     * @param  array<string, int>  $seenCodes  Code (lowercased) → row number.
     */
    private function resolveParents(Collection $existing, array $seenCodes): void
    {
        // Code → parent code, across the file AND the chart already stored,
        // so a cycle closed through an untouched account is still caught.
        $parentOf = [];

        foreach ($existing as $key => $account) {
            $parentCode = $account->parent?->code;
            $parentOf[$key] = $parentCode === null ? null : mb_strtolower($parentCode);
        }

        foreach ($this->parsed as $row) {
            if ($row['code'] === null) {
                continue;
            }

            $parentOf[mb_strtolower((string) $row['code'])] = $row['parent_code'] === null
                ? null
                : mb_strtolower((string) $row['parent_code']);
        }

        foreach ($this->parsed as $index => $row) {
            $parentCode = $row['parent_code'];

            if ($parentCode === null || $row['code'] === null) {
                if ($row['code'] !== null) {
                    $account = $existing->get(mb_strtolower((string) $row['code']));
                    $this->set($this->parsed[$index], 'parent_id', null, $account?->parent_id);
                }

                continue;
            }

            $key = mb_strtolower((string) $parentCode);
            $ownKey = mb_strtolower((string) $row['code']);

            if ($key === $ownKey) {
                $this->parsed[$index]['errors'][] = 'An account cannot be its own parent.';

                continue;
            }

            $parent = $existing->get($key);
            $inFile = isset($seenCodes[$key]);

            if ($parent === null && ! $inFile) {
                $this->parsed[$index]['errors'][] = sprintf(
                    'No account with code %s exists, and the file does not create one.',
                    $parentCode,
                );

                continue;
            }

            if ($this->wouldCycle($ownKey, $parentOf)) {
                $this->parsed[$index]['errors'][] = sprintf(
                    'Parent %s closes a loop — an account cannot end up beneath itself.',
                    $parentCode,
                );

                continue;
            }

            // A parent only created by this same file has no id yet. The row
            // is valid; the action resolves the id after the parents are
            // written, which is why it keeps the code.
            $account = $existing->get($ownKey);
            $this->set(
                $this->parsed[$index],
                'parent_id',
                $parent?->getKey(),
                $account?->parent_id,
            );
        }
    }

    /**
     * Walks up from an account to see whether it reaches itself again.
     *
     * @param  array<string, string|null>  $parentOf
     */
    private function wouldCycle(string $start, array $parentOf): bool
    {
        $seen = [$start => true];
        $current = $parentOf[$start] ?? null;

        while ($current !== null) {
            if (isset($seen[$current])) {
                return true;
            }

            $seen[$current] = true;
            $current = $parentOf[$current] ?? null;
        }

        return false;
    }

    /**
     * An update whose every column already matches is reported, not applied.
     *
     * Run after the parent pass, because a row can be identical in every other
     * respect and still be moving under a new parent.
     */
    private function markUnchanged(): void
    {
        foreach ($this->parsed as $index => $row) {
            if ($row['action'] === 'update' && $row['changes'] === []) {
                $this->parsed[$index]['action'] = 'unchanged';
            }
        }
    }

    /**
     * Records the value to write, and the change if it moves.
     *
     * @param  array<string, mixed>  $entry
     */
    private function set(array &$entry, string $field, mixed $value, mixed $current): void
    {
        $entry['attributes'][$field] = $value;

        $unchanged = is_bool($value)
            ? (bool) $current === $value
            : $current === $value;

        if (! $unchanged) {
            $entry['changes'][$field] = ['from' => $current, 'to' => $value];
        }
    }

    /**
     * @param  array<string, mixed>|Collection<string, mixed>  $raw
     */
    private function isBlankRow(mixed $raw): bool
    {
        foreach (['code_do_not_change', 'code', 'name', 'type'] as $key) {
            $value = $raw[$key] ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
