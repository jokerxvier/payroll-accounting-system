<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 5 — the seller's own identity, for the face of an invoice.
 *
 * A BIR sales invoice has to show who issued it: the registered name, the
 * TIN, and the registered business address. `pas_schools` carried only a
 * display name, which is enough for a sidebar and not enough for a document
 * handed to a customer.
 *
 * All three are nullable, and the printed document omits whichever are
 * missing rather than showing empty labels. An unregistered school is a
 * normal state — the same treatment the Authority To Print footer gets, and
 * for the same reason: these are facts about the client's registration that
 * only the client can supply.
 *
 * `registered_name` is separate from `name` because they legitimately
 * differ: a school trades as "St. Jude Academy" and is registered as "St.
 * Jude Academy Educational Foundation, Inc." The document must show the
 * registered form; every screen keeps showing the short one.
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
            if (! Schema::hasColumn('pas_schools', 'registered_name')) {
                $table->string('registered_name', 255)->nullable()->after('name');
            }

            if (! Schema::hasColumn('pas_schools', 'tin')) {
                $table->string('tin', 32)->nullable()->after('registered_name');
            }

            if (! Schema::hasColumn('pas_schools', 'business_address')) {
                $table->text('business_address')->nullable()->after('tin');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_schools')) {
            return;
        }

        Schema::table('pas_schools', function (Blueprint $table): void {
            foreach (['registered_name', 'tin', 'business_address'] as $column) {
                if (Schema::hasColumn('pas_schools', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
