<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a document carried in from the school's previous books.
 *
 * Slice 9's cutover snapshot puts the AR/AP totals into the ledger as one
 * dated journal entry. An open item is one of the individual unpaid invoices
 * *behind* that total — so it is a document with no journal entry of its own,
 * because the money it represents is already in the control account and
 * posting it again would count it twice.
 *
 * **A boolean rather than inferring it from a null `journal_entry_id`.** That
 * column is already null for every draft, and every reader today takes it to
 * mean "not approved yet" — `InvoicePostingService::hasPosted()` and the
 * invoice detail payload both do. Once approved-but-never-posted rows exist
 * that inference is wrong, and the interface would start telling an officer a
 * real receivable had never been posted. This flag keeps the two apart.
 *
 * It is also the only queryable link back to the cutover: `PostOpeningBalances`
 * records nothing per account beyond the journal entry's own lines, so without
 * a column here there is no way to ask "which documents make up the opening
 * receivable" — which is the whole point of recording them.
 *
 * Indexed with `school_id` because the reconciliation figure sums exactly this
 * subset, and it is read every time the invoice dashboard renders.
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
            if (! Schema::hasColumn('pas_invoices', 'is_opening_item')) {
                // Default false: every invoice raised before this column
                // existed was transacted here, which is what false means.
                $table->boolean('is_opening_item')
                    ->default(false)
                    ->after('journal_entry_id');

                $table->index(
                    ['school_id', 'is_opening_item'],
                    'pas_inv_school_opening_idx',
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_invoices')) {
            return;
        }

        Schema::table('pas_invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_invoices', 'is_opening_item')) {
                $table->dropIndex('pas_inv_school_opening_idx');
                $table->dropColumn('is_opening_item');
            }
        });
    }
};
