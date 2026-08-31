<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school's own mark, for the documents it hands out.
 *
 * Every document this system produces is currently anonymous. An invoice
 * carries the registered name and TIN that Slice 5 added; a payslip carries
 * no employer identity at all. A logo is the piece that makes both read as
 * the school's rather than the software's.
 *
 * Nullable, and the printed document omits it rather than reserving space —
 * the same treatment `registered_name` / `tin` / `business_address` get, and
 * for the same reason: a school that has not supplied one is a normal state,
 * not a defect.
 *
 * Stores a PATH on the `public` disk, not the bytes. A logo is printed on
 * invoices handed to parents, so it is not confidential, and keeping it on a
 * disk the web server can serve directly avoids a PHP request for an image on
 * every page load. The path carries a content hash, so replacing a logo
 * changes the URL and no cache can serve the old one.
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
            if (! Schema::hasColumn('pas_schools', 'logo_path')) {
                $table->string('logo_path', 255)->nullable()->after('business_address');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_schools')) {
            return;
        }

        Schema::table('pas_schools', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_schools', 'logo_path')) {
                $table->dropColumn('logo_path');
            }
        });
    }
};
