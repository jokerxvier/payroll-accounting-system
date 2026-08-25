<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 8b — record which accounts actually ARE cash.
 *
 * Nothing on the table answered that question. `cash_flow_category` is set on
 * every account including the cash ones — it says which section of the Cash
 * Flow Statement an account's movement belongs to, not whether the account is
 * itself part of the cash balance those sections reconcile to. A Cash Flow
 * Statement needs both, so the flag is a new column rather than another value
 * in the existing one.
 *
 * It also closes a live control gap. `PaymentController::cashAccountOptions()`
 * approximated cash as "any active asset with no `system_code`", which offered
 * Prepaid Expenses and Property, Plant and Equipment as accounts to receive a
 * payment into, and `PaymentRequest` only checked that the account was an
 * asset. Both now key off this column.
 *
 * **Backfill is by seeded code, deliberately narrow.** Only `1100 Cash on
 * Hand` and `1110 Cash in Bank` — the two cash accounts
 * `AccountingCatalogSeeder` ships — are flagged, and the type is re-checked so
 * a school that reused one of those codes for something else is not swept in.
 * A school that renumbered its chart gets nothing here and ticks its own
 * accounts in the UI.
 *
 * Defaulting to false is the safe direction. An account wrongly left off the
 * list is visible immediately — it is missing from the payment form's account
 * picker, and someone says so. An account wrongly included is the bug this
 * migration exists to fix, and it is silent.
 *
 * Idempotent on both halves: the column add is guarded by `hasColumn`, and the
 * backfill is an UPDATE to a fixed value.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    /**
     * Codes `AccountingCatalogSeeder` ships as cash accounts.
     *
     * @var list<string>
     */
    private const CASH_CODES = ['1100', '1110'];

    public function up(): void
    {
        if (! Schema::hasTable('pas_chart_of_accounts')) {
            return;
        }

        if (! Schema::hasColumn('pas_chart_of_accounts', 'is_cash_equivalent')) {
            Schema::table('pas_chart_of_accounts', function (Blueprint $table): void {
                $table->boolean('is_cash_equivalent')
                    ->default(false)
                    ->after('cash_flow_category');
            });
        }

        DB::table('pas_chart_of_accounts')
            ->whereIn('code', self::CASH_CODES)
            ->where('type', 'asset')
            ->update(['is_cash_equivalent' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pas_chart_of_accounts', 'is_cash_equivalent')) {
            return;
        }

        Schema::table('pas_chart_of_accounts', function (Blueprint $table): void {
            $table->dropColumn('is_cash_equivalent');
        });
    }
};
