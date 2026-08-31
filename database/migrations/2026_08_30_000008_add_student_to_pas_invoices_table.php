<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the charges on an invoice are for.
 *
 * `contact_id` says who owes the money. It cannot say who was taught, and in a
 * school those are different people: the parent pays, the student is charged.
 * Until now the only place a student could be named was the free-text
 * `reference` field, whose placeholder literally reads "Student or PO
 * reference" — a string with no identity, unsearchable and unjoinable.
 *
 * **Nullable**, because not every sales invoice is for a student. A school
 * also bills organisations for facility hire, suppliers for recharges, and
 * anything else that has no pupil behind it.
 *
 * `student_name` is a snapshot taken at issue. An invoice is a document handed
 * to a third party; what it says happened must not change because someone
 * later corrected a spelling in the LMS. It also spares every render of a
 * historical invoice a cross-connection lookup.
 *
 * No foreign key on `lms_student_id` — different connection — so it is only
 * meaningful with `school_id`, which the row already carries.
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
            if (! Schema::hasColumn('pas_invoices', 'lms_student_id')) {
                $table->unsignedBigInteger('lms_student_id')->nullable()->after('contact_id');
                $table->string('student_name', 160)->nullable()->after('lms_student_id');
                $table->index(['school_id', 'lms_student_id'], 'pas_inv_school_student_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_invoices')) {
            return;
        }

        Schema::table('pas_invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_invoices', 'lms_student_id')) {
                $table->dropIndex('pas_inv_school_student_idx');
                $table->dropColumn(['lms_student_id', 'student_name']);
            }
        });
    }
};
