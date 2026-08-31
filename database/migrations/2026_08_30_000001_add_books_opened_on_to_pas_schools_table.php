<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 9 — the cutover date a school's books were opened on.
 *
 * Until now the ledger started at zero and every figure in it was posted,
 * so there was nothing to record: the first entry WAS the beginning. Once a
 * school can carry balances in from whatever it kept books in before, the
 * boundary between "brought in as an opening snapshot" and "transacted in
 * this system" stops being obvious from the data, and a report reader has no
 * way to tell one from the other.
 *
 * Nullable, because a school that genuinely started from zero has no cutover
 * and must not be made to invent one. Null reads as "these books have always
 * been kept here", which is the honest default and the state every existing
 * row is in.
 *
 * Stamped by `PostOpeningBalances`, never by hand: the date belongs to the
 * opening entry, and letting it drift from the entry it describes would make
 * the note on the report lie. Reversing the snapshot clears it back to null.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_schools')) {
            return;
        }

        Schema::table('pas_schools', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_schools', 'books_opened_on')) {
                $table->date('books_opened_on')->nullable()->after('business_address');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_schools')) {
            return;
        }

        Schema::table('pas_schools', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_schools', 'books_opened_on')) {
                $table->dropColumn('books_opened_on');
            }
        });
    }
};
