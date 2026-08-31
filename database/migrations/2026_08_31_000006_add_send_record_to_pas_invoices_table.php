<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the invoice was last emailed.
 *
 * `sent_at` has existed since the table was created and answers "when", which
 * is only half of the question anyone actually asks. When a parent says the
 * invoice never arrived, the useful record is the address it went to — and
 * with a manual send the operator may have typed one that is nowhere else in
 * the system, because a one-off send to a grandparent's address deliberately
 * does not write itself back to the contact.
 *
 * 160 characters, matching `pas_contacts.email`. Nullable: every invoice
 * raised before this column existed has a null here, and so does every one
 * nobody has sent.
 *
 * No index. Nothing filters or sorts on it; it is read only when a single
 * invoice is already on screen.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_invoices')) {
            return;
        }

        Schema::table('pas_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_invoices', 'sent_to')) {
                $table->string('sent_to', 160)->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_invoices')) {
            return;
        }

        Schema::table('pas_invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_invoices', 'sent_to')) {
                $table->dropColumn('sent_to');
            }
        });
    }
};
