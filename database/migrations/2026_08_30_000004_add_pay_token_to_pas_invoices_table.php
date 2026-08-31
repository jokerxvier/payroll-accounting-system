<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public identifier a customer's pay link hangs off.
 *
 * `pas_invoices` is keyed by an auto-incrementing id, which is fine while
 * every route is authenticated and useless the moment one is not: an
 * enumerable id in a public URL lets anyone walk the whole table. This column
 * is the unguessable alternative, and it is the ACTUAL credential on the
 * public route — the `/schools/{slug}/` prefix that resolves the tenant is
 * spoofable by a guest, so the lookup matches on school AND token together.
 *
 * **Nullable, and minted on demand** rather than at creation. An invoice
 * nobody shares never gets a token, which keeps the number of live public
 * URLs equal to the number someone deliberately created. Once minted it is
 * stable — regenerating would silently break a link already in a parent's
 * hands.
 *
 * 40 characters of `Str::random()`, unique per school.
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
            if (! Schema::hasColumn('pas_invoices', 'pay_token')) {
                $table->string('pay_token', 40)->nullable()->after('number');
                $table->unique(['school_id', 'pay_token'], 'pas_invoices_school_pay_token_unq');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_invoices')) {
            return;
        }

        Schema::table('pas_invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_invoices', 'pay_token')) {
                $table->dropUnique('pas_invoices_school_pay_token_unq');
                $table->dropColumn('pay_token');
            }
        });
    }
};
