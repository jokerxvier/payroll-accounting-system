<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which students a contact pays for.
 *
 * The table exists because the alternative does not work. A column on
 * `pas_contacts` can hold one student; a parent with two children would then
 * need two contact rows, which scatters one family's receivable across two
 * counterparties, breaks their statement, and counts the same person twice in
 * Aged Receivables. One payer, many students — so the link gets its own row.
 *
 * **Many-to-many in both directions, on purpose.** A contact has several
 * children; a student has a primary payer and may also have a sponsor or a
 * second guardian. The LMS models neither side of that — `sm_students.parent_id`
 * is a single edge to a single row holding father, mother and guardian as text
 * columns — so every part of the billing relationship is expressed here.
 *
 * `is_primary_payer` is what "select the student, load the parent"
 * resolves through. **Exactly one primary per student is enforced in the
 * action, not the schema**: "exactly one of a filtered set" is not something a
 * unique index can express, and a partial index is not portable to the sqlite
 * the suite runs on.
 *
 * `lms_student_id` carries no foreign key — it points into the LMS database,
 * a different connection — and is therefore only meaningful alongside
 * `school_id`. Student id 29 exists in BOTH tenant databases, so every
 * constraint and lookup here is composite. A bare unique on `lms_student_id`
 * would collide the moment a second school imported.
 *
 * `student_name` is a snapshot rather than a join. The LMS is another
 * connection, and listing a contact's children should not require a
 * cross-database query to render — nor should a name change in the LMS
 * silently rewrite what a historical document said.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_contact_students')) {
            return;
        }

        Schema::create('pas_contact_students', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('contact_id');

            // No FK — a different database. See the class docblock.
            $table->unsignedBigInteger('lms_student_id');
            $table->string('student_name', 160);

            // From sm_parents.guardians_relation — free text in the LMS
            // ("Father", "Mother", anything the operator typed).
            $table->string('relationship', 40)->nullable();

            $table->boolean('is_primary_payer')->default(false);
            $table->timestamps();

            $table->unique(
                ['school_id', 'contact_id', 'lms_student_id'],
                'pas_contact_students_unq',
            );
            $table->index(
                ['school_id', 'lms_student_id'],
                'pas_contact_students_school_student_idx',
            );

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            // Cascade: the link has no meaning without the payer, and a
            // contact that has been invoiced cannot be deleted anyway
            // (pas_invoices.contact_id restricts).
            $table->foreign('contact_id')
                ->references('id')
                ->on('pas_contacts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_contact_students');
    }
};
