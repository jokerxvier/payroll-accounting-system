<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 1 follow-up — give schools that already existed their
 * accounting catalogs.
 *
 * `SchoolObserver` clones the default school's chart of accounts and tax
 * rates onto every school it sees created. Schools created *before* Slice 1
 * shipped never fired that hook, so they hold allowances and deduction types
 * (cloned when they were created) but an empty ledger vocabulary — switching
 * to one shows a blank chart and no VAT rates.
 *
 * This is the same catch-up the Phase-D conversion did for the payroll
 * catalogs in 2026_05_10_000001's `cloneCatalogsForNonDefaultSchools()`, and
 * it follows that migration's shape deliberately.
 *
 * The clone logic is inlined rather than calling into `SchoolObserver`. A
 * migration is a historical record that must keep producing the same result
 * years after the application code around it has moved on; reaching into a
 * class that is free to change would make this migration's behaviour depend
 * on a future refactor.
 *
 * Idempotent: a school is skipped entirely when it already holds any rows in
 * the target table, so re-running never duplicates and never overwrites a
 * tenant that has started customising.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pas_schools', 'pas_chart_of_accounts', 'pas_tax_rates'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $defaultSchoolId = DB::table('pas_schools')->where('slug', 'default')->value('id');

        // Fresh-bootstrap env (RefreshDatabase in tests, first deploy before
        // seeding). Nothing to clone from; the observer handles schools
        // created later.
        if ($defaultSchoolId === null) {
            return;
        }

        $defaultSchoolId = (int) $defaultSchoolId;

        $targets = DB::table('pas_schools')
            ->where('id', '!=', $defaultSchoolId)
            ->orderBy('id')
            ->pluck('id');

        foreach ($targets as $targetId) {
            $this->cloneChartOfAccounts($defaultSchoolId, (int) $targetId);
            $this->cloneTaxRates($defaultSchoolId, (int) $targetId);
        }
    }

    /**
     * Data-only backfill; there is nothing safe to reverse.
     *
     * Cloned rows become indistinguishable from rows the tenant has since
     * edited, so a blanket delete on rollback would discard real work. Same
     * reasoning as 2026_05_10_000001, which also leaves its clones in place.
     */
    public function down(): void
    {
        // Intentionally empty — see the docblock above.
    }

    /**
     * Clone the chart, then re-point `parent_id` at the target school's own
     * rows. Two passes: a parent may appear after its child in the source
     * set, so no single insert order resolves every parent.
     */
    private function cloneChartOfAccounts(int $sourceId, int $targetId): void
    {
        $table = 'pas_chart_of_accounts';

        if (DB::table($table)->where('school_id', $targetId)->exists()) {
            return;
        }

        $sourceRows = DB::table($table)->where('school_id', $sourceId)->get();

        if ($sourceRows->isEmpty()) {
            return;
        }

        $now = now();

        DB::table($table)->insert(
            $sourceRows->map(function (object $row) use ($targetId, $now): array {
                $clone = $this->rebase((array) $row, $targetId, $now);
                $clone['parent_id'] = null;

                return $clone;
            })->all()
        );

        // `code` is unique per school, which makes it a safe join key across
        // the two sets.
        $sourceCodeById = $sourceRows->pluck('code', 'id');
        $targetIdByCode = DB::table($table)->where('school_id', $targetId)->pluck('id', 'code');

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

            DB::table($table)->where('id', $newChildId)->update(['parent_id' => $newParentId]);
        }
    }

    /**
     * Clone the tax rates, re-pointing `account_id` at the target school's
     * equivalent account, matched by account code.
     *
     * Copying `account_id` verbatim would leave the new school's rates
     * pointing at the DEFAULT school's accounts — a cross-tenant reference
     * the global scope cannot catch, because the FK resolves by id rather
     * than through a scoped query.
     */
    private function cloneTaxRates(int $sourceId, int $targetId): void
    {
        $table = 'pas_tax_rates';

        if (DB::table($table)->where('school_id', $targetId)->exists()) {
            return;
        }

        $sourceRows = DB::table($table)->where('school_id', $sourceId)->get();

        if ($sourceRows->isEmpty()) {
            return;
        }

        $sourceAccountCodeById = DB::table('pas_chart_of_accounts')
            ->where('school_id', $sourceId)->pluck('code', 'id');
        $targetAccountIdByCode = DB::table('pas_chart_of_accounts')
            ->where('school_id', $targetId)->pluck('id', 'code');

        $now = now();

        DB::table($table)->insert(
            $sourceRows->map(function (object $row) use (
                $targetId,
                $now,
                $sourceAccountCodeById,
                $targetAccountIdByCode
            ): array {
                $clone = $this->rebase((array) $row, $targetId, $now);

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
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rebase(array $row, int $targetId, mixed $now): array
    {
        unset($row['id']);
        $row['school_id'] = $targetId;
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        return $row;
    }
};
