<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which LMS parent record a contact was imported from.
 *
 * The import's primary de-duplication key, and the only one that is certain.
 * Matching on name or email is a heuristic; matching on the source row's id is
 * a fact — so re-running the import, or importing a second sibling, finds the
 * existing payer instead of creating a rival copy of the same person.
 *
 * Unique per school, never globally: the LMS ids repeat across tenants because
 * each school has its own LMS database, and parent id 29 exists in both.
 *
 * No foreign key, for the same reason as `lms_student_id` beside it — the
 * target lives on a different connection.
 *
 * Note this does NOT replace `lms_student_id`, which stays as it was: a
 * singular student pointer cannot express a parent who pays for two children,
 * and reusing it would force exactly the duplication this feature exists to
 * avoid. It remains unpopulated; removing it is a separate decision.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_contacts')) {
            return;
        }

        Schema::table('pas_contacts', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_contacts', 'lms_parent_id')) {
                $table->unsignedBigInteger('lms_parent_id')->nullable()->after('lms_student_id');
                $table->unique(['school_id', 'lms_parent_id'], 'pas_contacts_school_lms_parent_unq');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_contacts')) {
            return;
        }

        Schema::table('pas_contacts', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_contacts', 'lms_parent_id')) {
                $table->dropUnique('pas_contacts_school_lms_parent_unq');
                $table->dropColumn('lms_parent_id');
            }
        });
    }
};
