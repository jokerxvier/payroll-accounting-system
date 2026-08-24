<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 7 — give every existing chart the two advances accounts.
 *
 * A payment can exceed what it is allocated against: a parent pays a round
 * ₱50,000 against a ₱30,000 invoice. That remainder is a liability owed back
 * in goods, not a receivable owed to us, so it credits its own account rather
 * than pushing Accounts Receivable into a negative balance.
 *
 * Unlike the Slice 1 backfill, this is not a clone. Every school already
 * holds a chart of accounts by now — they are simply two rows short — so this
 * inserts the missing rows per school instead of copying the default's whole
 * catalog.
 *
 * `AccountingCatalogSeeder` carries the same two rows for schools created
 * from here on, and `SchoolObserver` clones the default's chart wholesale, so
 * neither needs changing.
 *
 * Idempotent: a school already holding the system code is skipped, and the
 * code is skipped separately in case a tenant hand-created an account at
 * `1450` or `2410` before this ran. Both uniques on the table —
 * (school_id, code) and (school_id, system_code) — are therefore respected.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    /**
     * @var list<array<string, string>>
     */
    private const ACCOUNTS = [
        [
            'code' => '1450',
            'name' => 'Advances to Suppliers',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'normal_balance' => 'debit',
            'cash_flow_category' => 'operating',
            'system_code' => 'SUPPLIER_ADVANCES',
            'description' => 'Money paid to a supplier that no bill has claimed yet. Cleared as bills are allocated against it.',
        ],
        [
            'code' => '2410',
            'name' => 'Advances from Customers',
            'type' => 'liability',
            'subtype' => 'current_liability',
            'normal_balance' => 'credit',
            'cash_flow_category' => 'operating',
            'system_code' => 'CUSTOMER_ADVANCES',
            'description' => 'Money received that no invoice has claimed yet. Distinct from Unearned Tuition Revenue, which is tuition billed but not yet earned.',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pas_schools') || ! Schema::hasTable('pas_chart_of_accounts')) {
            return;
        }

        $now = now();

        // Only schools that already hold a chart. One with none has never
        // been seeded, and AccountingCatalogSeeder / SchoolObserver will give
        // it the full catalog including these two.
        $schoolIds = DB::table('pas_chart_of_accounts')
            ->distinct()
            ->orderBy('school_id')
            ->pluck('school_id');

        foreach ($schoolIds as $schoolId) {
            foreach (self::ACCOUNTS as $account) {
                $exists = DB::table('pas_chart_of_accounts')
                    ->where('school_id', $schoolId)
                    ->where(function ($query) use ($account): void {
                        $query->where('system_code', $account['system_code'])
                            ->orWhere('code', $account['code']);
                    })
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('pas_chart_of_accounts')->insert([
                    ...$account,
                    'school_id' => $schoolId,
                    'parent_id' => null,
                    'is_active' => true,
                    // Locked, like every other account the software posts to
                    // by itself — re-coding or deleting it would break
                    // payment posting.
                    'is_locked' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Data-only backfill; there is nothing safe to reverse.
     *
     * By the time anyone rolls this back the accounts may already carry
     * posted payments, and dropping an account with ledger history behind it
     * is exactly what `restrictOnDelete` on the journal lines exists to
     * prevent. Same reasoning as the Slice 1 backfill.
     */
    public function down(): void
    {
        // Intentionally empty — see the docblock above.
    }
};
