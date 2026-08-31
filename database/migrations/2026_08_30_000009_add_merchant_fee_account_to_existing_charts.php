<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every existing chart the account gateway fees are expensed to.
 *
 * A payment gateway keeps a cut of every settlement, and until now the
 * settings screen made someone choose where that lands from a list where
 * nothing fitted: `5900 Miscellaneous Expense` is a dumping ground, `5240
 * Professional Fees` is not what a gateway cut is, and `5400 Interest
 * Expense` is categorised `financing` when a merchant fee is `operating`.
 *
 * With a proper account carrying `MERCHANT_FEES`, the question stops being
 * asked at all — the posting resolves it the same way it already resolves AR
 * control and Customer Advances.
 *
 * `AccountingCatalogSeeder` carries the same row for schools created from here
 * on, and `SchoolObserver` clones the default's chart wholesale, so neither
 * needs changing.
 *
 * Idempotent: a school already holding the system code is skipped, and the
 * code is skipped separately in case a tenant hand-created an account at
 * `5250` before this ran. Both uniques on the table — (school_id, code) and
 * (school_id, system_code) — are therefore respected. A school that hand-built
 * `5250` keeps its own account and simply never gains the system code; posting
 * then fails with a legible setup error rather than silently using a row
 * nobody designated.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const ACCOUNT = [
        'code' => '5250',
        'name' => 'Bank and Merchant Fees',
        'type' => 'expense',
        'subtype' => 'operating_expense',
        'normal_balance' => 'debit',
        'cash_flow_category' => 'operating',
        'system_code' => 'MERCHANT_FEES',
        'description' => 'What a payment gateway or bank keeps out of a settlement. Posted automatically alongside every online receipt, so the cost of collecting stays visible rather than netted against income.',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pas_schools') || ! Schema::hasTable('pas_chart_of_accounts')) {
            return;
        }

        $now = now();

        // Only schools that already hold a chart. One with none has never
        // been seeded, and AccountingCatalogSeeder / SchoolObserver will give
        // it the full catalog including this row.
        $schoolIds = DB::table('pas_chart_of_accounts')
            ->distinct()
            ->orderBy('school_id')
            ->pluck('school_id');

        foreach ($schoolIds as $schoolId) {
            $exists = DB::table('pas_chart_of_accounts')
                ->where('school_id', $schoolId)
                ->where(function ($query): void {
                    $query->where('system_code', self::ACCOUNT['system_code'])
                        ->orWhere('code', self::ACCOUNT['code']);
                })
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('pas_chart_of_accounts')->insert([
                ...self::ACCOUNT,
                'school_id' => $schoolId,
                'parent_id' => null,
                'is_active' => true,
                // Locked, like every other account the software posts to by
                // itself — re-coding or deleting it would break gateway
                // posting.
                'is_locked' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Data-only backfill; there is nothing safe to reverse.
     *
     * By the time anyone rolls this back the account may already carry posted
     * fees, and dropping an account with ledger history behind it is exactly
     * what `restrictOnDelete` on the journal lines exists to prevent.
     */
    public function down(): void
    {
        // Intentionally empty — see the docblock above.
    }
};
