<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Pas\ChartOfAccount;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Applies a parsed chart worksheet: creates what is new, updates what moved.
 *
 * Two passes, and the second is not optional. A sheet may nest an account
 * under a parent the same sheet creates, so every row is written first with
 * its parent left alone, then the parents are linked once every code has an
 * id. Doing it in one pass would refuse a perfectly ordinary chart typed in
 * code order.
 *
 * One transaction for the file, for a stronger reason than the contact
 * importer has: every posted journal line points at an account id, so a
 * half-written chart is a ledger whose accounts do not all exist yet.
 */
final class ApplyChartOfAccountImport
{
    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array{created: int, updated: int, unchanged: int}
     *
     * @throws DomainException When any row still carries an error.
     */
    public function execute(array $parsed): array
    {
        $withErrors = array_filter(
            $parsed,
            static fn (array $row): bool => ! empty($row['errors']),
        );

        if ($withErrors !== []) {
            throw new DomainException(sprintf(
                '%d row%s still need fixing.',
                count($withErrors),
                count($withErrors) === 1 ? '' : 's',
            ));
        }

        return DB::transaction(function () use ($parsed): array {
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($parsed as $row) {
                $action = $row['action'] ?? null;

                /** @var array<string, mixed> $attributes */
                $attributes = $row['attributes'] ?? [];

                // Written in the second pass, once every code has an id.
                unset($attributes['parent_id']);

                if ($action === 'unchanged') {
                    $unchanged++;

                    continue;
                }

                if ($action === 'create') {
                    ChartOfAccount::create(['code' => $row['code'], ...$attributes]);
                    $created++;

                    continue;
                }

                $accountId = $row['account_id'] ?? null;

                if (! is_int($accountId)) {
                    continue;
                }

                $account = ChartOfAccount::query()->find($accountId);

                if ($account === null) {
                    // Deleted between preview and confirm. Skipped rather than
                    // recreated — resurrecting it would undo a deliberate
                    // deletion somebody made in the meantime.
                    continue;
                }

                // A locked account keeps its code whatever the sheet says.
                // The importer refuses a type change on one; the code is not
                // in `$attributes` at all, so it is safe by construction.
                $account->update($attributes);
                $updated++;
            }

            $this->linkParents($parsed);

            return [
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
            ];
        });
    }

    /**
     * Second pass — resolve every `parent_code` now that all rows exist.
     *
     * Runs over ALL rows including the unchanged ones: a row can be identical
     * in every other respect while its parent is a code this same file has
     * only just created.
     *
     * @param  array<int, array<string, mixed>>  $parsed
     */
    private function linkParents(array $parsed): void
    {
        $codes = [];

        foreach ($parsed as $row) {
            if (is_string($row['code'] ?? null)) {
                $codes[] = $row['code'];
            }

            if (is_string($row['parent_code'] ?? null)) {
                $codes[] = $row['parent_code'];
            }
        }

        if ($codes === []) {
            return;
        }

        $byCode = ChartOfAccount::query()
            ->whereIn('code', array_values(array_unique($codes)))
            ->get()
            ->keyBy(fn (ChartOfAccount $account): string => mb_strtolower($account->code));

        foreach ($parsed as $row) {
            $code = $row['code'] ?? null;

            if (! is_string($code)) {
                continue;
            }

            $account = $byCode->get(mb_strtolower($code));

            if (! $account instanceof ChartOfAccount) {
                continue;
            }

            $parentCode = $row['parent_code'] ?? null;
            $parentId = is_string($parentCode)
                ? $byCode->get(mb_strtolower($parentCode))?->getKey()
                : null;

            if ($account->parent_id === $parentId) {
                continue;
            }

            $account->update(['parent_id' => $parentId]);
        }
    }
}
