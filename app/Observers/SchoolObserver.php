<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pas\School;
use Illuminate\Support\Facades\DB;

/**
 * Auto-clones the default school's per-tenant catalogs onto every
 * newly-created school:
 *
 *   flat catalogs      — pas_allowances, pas_deduction_types
 *   relational catalogs — pas_chart_of_accounts, pas_tax_rates
 *
 * Hooked on `created` (not `creating`) because we need the new school's id
 * to populate `school_id` on the cloned rows.
 *
 * Idempotency:
 *   - The clone is gated per-table on the new school having ZERO rows in
 *     that catalog. If a caller pre-seeded the new school with custom rows
 *     before the observer fires, those custom rows are preserved.
 *   - The default-school lookup is by `slug = 'default'`. If the default
 *     school doesn't exist yet (fresh-bootstrap dev / test env), we skip
 *     silently — same defensive pattern the Stage A migration uses.
 *   - The observer fires when the default school itself is created (by the
 *     SchoolSeeder); we no-op when `new->id == default->id` so we don't
 *     try to clone default's catalog onto itself.
 *
 * Why an observer (not an action invoked from the controller):
 *   - The observer fires on every `School::create()` — controllers,
 *     factories, seeders, tinker. We don't have to remember to call an
 *     action at every creation site.
 *
 * Why raw DB inserts (not Eloquent create):
 *   - The catalog models use `BelongsToTenant`, whose `creating` hook
 *     auto-fills `school_id` from `Tenant::current()`. If we used Eloquent
 *     `create()` here, the rows would land on the *current* tenant's id,
 *     not on the *new* school's id. Raw inserts via the query builder
 *     bypass the trait so we can write the explicit target `school_id`.
 *   - Bypassing the model also means `Auditable` does not log these rows
 *     individually — the observer's job is bulk-clone, not user-driven
 *     edits, and a cloned row's history starts at the operator's first
 *     edit (which DOES go through Eloquent and IS audited).
 *
 * Why the accounting catalogs need their own code path:
 *   Unlike allowances and deduction types, the Phase 5 catalogs carry FKs to
 *   *other rows in the same clone set* — `pas_chart_of_accounts.parent_id`
 *   points at another account, and `pas_tax_rates.account_id` points at the
 *   VAT account. Copying those columns verbatim would leave the new school's
 *   rows referencing the DEFAULT school's account ids: a silent cross-tenant
 *   leak that the global scope cannot catch, because the FK is resolved by id
 *   rather than through a scoped query. Both are therefore re-pointed by
 *   `code` — which is unique per school — after the accounts are inserted and
 *   their new ids are known.
 *
 * The whole clone runs in a single transaction so a partial failure
 * (e.g., disk full mid-table) doesn't leave the new school with
 * one catalog populated and the others empty.
 */
final class SchoolObserver
{
    /**
     * Catalogs with no intra-set foreign keys — a straight row copy is safe.
     *
     * @var list<string>
     */
    private const FLAT_CATALOG_TABLES = [
        'pas_allowances',
        'pas_deduction_types',
    ];

    public function created(School $school): void
    {
        $defaultSchool = School::query()
            ->withoutGlobalScopes()
            ->where('slug', 'default')
            ->first();

        // Fresh-bootstrap env (no default seeded yet — happens during
        // SchoolSeeder::run() itself when the default is created). Skip
        // silently; future creations will find the default and clone.
        if ($defaultSchool === null) {
            return;
        }

        // Don't try to clone default's catalogs onto itself when this
        // observer fires for the default-school creation event.
        if ($defaultSchool->getKey() === $school->getKey()) {
            return;
        }

        $sourceId = (int) $defaultSchool->getKey();
        $targetId = (int) $school->getKey();

        DB::transaction(function () use ($sourceId, $targetId): void {
            $now = now();

            foreach (self::FLAT_CATALOG_TABLES as $table) {
                $this->cloneFlatCatalog($table, $sourceId, $targetId, $now);
            }

            // Order matters: tax rates FK to the chart of accounts, so the
            // accounts must exist (and their new ids be known) first.
            $this->cloneChartOfAccounts($sourceId, $targetId, $now);
            $this->cloneTaxRates($sourceId, $targetId, $now);
        });
    }

    /**
     * Copy every row of `$table` from the source school to the target,
     * regenerating ids and timestamps. No-op when the target already has
     * rows of its own.
     */
    private function cloneFlatCatalog(string $table, int $sourceId, int $targetId, mixed $now): void
    {
        if ($this->targetAlreadyPopulated($table, $targetId)) {
            return;
        }

        $sourceRows = DB::table($table)->where('school_id', $sourceId)->get();

        if ($sourceRows->isEmpty()) {
            return;
        }

        DB::table($table)->insert(
            $sourceRows->map(
                fn (object $row): array => $this->rebaseRow((array) $row, $targetId, $now)
            )->all()
        );
    }

    /**
     * Clone the chart of accounts, then re-point `parent_id` at the target
     * school's own rows.
     *
     * Two passes rather than one: an account's parent may appear after it in
     * the source set, so there is no insert order that lets a single pass
     * resolve every parent. Inserting with `parent_id = null` and fixing up
     * afterwards is order-independent — and leaves a coherent (merely flat)
     * hierarchy even if the second pass were to fail.
     */
    private function cloneChartOfAccounts(int $sourceId, int $targetId, mixed $now): void
    {
        $table = 'pas_chart_of_accounts';

        if ($this->targetAlreadyPopulated($table, $targetId)) {
            return;
        }

        $sourceRows = DB::table($table)->where('school_id', $sourceId)->get();

        if ($sourceRows->isEmpty()) {
            return;
        }

        DB::table($table)->insert(
            $sourceRows->map(function (object $row) use ($targetId, $now): array {
                $clone = $this->rebaseRow((array) $row, $targetId, $now);
                // Resolved in the second pass below, once new ids exist.
                $clone['parent_id'] = null;

                return $clone;
            })->all()
        );

        // code → id, for each school. `code` is unique per school, which is
        // what makes it a safe join key across the two sets.
        $sourceCodeById = $sourceRows->pluck('code', 'id');
        $targetIdByCode = DB::table($table)
            ->where('school_id', $targetId)
            ->pluck('id', 'code');

        foreach ($sourceRows as $row) {
            if ($row->parent_id === null) {
                continue;
            }

            $parentCode = $sourceCodeById[$row->parent_id] ?? null;
            $newParentId = $parentCode === null ? null : ($targetIdByCode[$parentCode] ?? null);
            $newChildId = $targetIdByCode[$row->code] ?? null;

            if ($newParentId === null || $newChildId === null) {
                continue;
            }

            DB::table($table)
                ->where('id', $newChildId)
                ->update(['parent_id' => $newParentId]);
        }
    }

    /**
     * Clone the tax-rate catalog, re-pointing `account_id` at the target
     * school's equivalent account (matched by account code).
     *
     * A rate whose source account cannot be resolved is cloned with a null
     * `account_id` rather than being skipped: the operator sees the rate in
     * their catalog and can attach the right account, which is a better
     * failure mode than a silently missing VAT rate.
     */
    private function cloneTaxRates(int $sourceId, int $targetId, mixed $now): void
    {
        $table = 'pas_tax_rates';

        if ($this->targetAlreadyPopulated($table, $targetId)) {
            return;
        }

        $sourceRows = DB::table($table)->where('school_id', $sourceId)->get();

        if ($sourceRows->isEmpty()) {
            return;
        }

        $sourceAccountCodeById = DB::table('pas_chart_of_accounts')
            ->where('school_id', $sourceId)
            ->pluck('code', 'id');

        $targetAccountIdByCode = DB::table('pas_chart_of_accounts')
            ->where('school_id', $targetId)
            ->pluck('id', 'code');

        DB::table($table)->insert(
            $sourceRows->map(function (object $row) use (
                $targetId,
                $now,
                $sourceAccountCodeById,
                $targetAccountIdByCode
            ): array {
                $clone = $this->rebaseRow((array) $row, $targetId, $now);

                $accountCode = $row->account_id === null
                    ? null
                    : ($sourceAccountCodeById[$row->account_id] ?? null);

                $clone['account_id'] = $accountCode === null
                    ? null
                    : ($targetAccountIdByCode[$accountCode] ?? null);

                return $clone;
            })->all()
        );
    }

    /**
     * Strip the source row's identity and stamp it for the target school.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rebaseRow(array $row, int $targetId, mixed $now): array
    {
        unset($row['id']);
        $row['school_id'] = $targetId;
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        return $row;
    }

    /**
     * Idempotency guard: only clone when the target school has zero rows in
     * this catalog. A re-fire (a tap-driven test, a seed run twice) is a
     * no-op, and any customisation already present is preserved.
     */
    private function targetAlreadyPopulated(string $table, int $targetId): bool
    {
        return DB::table($table)->where('school_id', $targetId)->exists();
    }
}
