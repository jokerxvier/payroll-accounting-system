<?php

declare(strict_types=1);

use App\Models\Pas\AuditLog;
use App\Models\Pas\Contact;
use App\Models\Pas\ContactStudent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * /admin/contacts/import-students.
 *
 * Pinned:
 *  - the gate is AccountingRoles::MANAGE, via ContactPolicy::create
 *  - the preview writes nothing, however many students it finds
 *  - a stale token cannot be replayed
 *  - the import is audited as one batch
 */

beforeEach(function (): void {
    useLmsSqliteMirror();

    ContactStudent::query()->withoutGlobalScopes()->delete();
    Contact::query()->withoutGlobalScopes()->delete();
    DB::connection('lms')->table('sm_students')->delete();
    DB::connection('lms')->table('sm_parents')->delete();

    DB::connection('lms')->table('sm_parents')->insert([
        'id' => 29,
        'guardians_name' => 'Zachary Roy',
        'guardians_email' => 'zach@example.test',
        'guardians_relation' => 'Father',
        'active_status' => 1,
        'school_id' => 1,
    ]);
    DB::connection('lms')->table('sm_students')->insert([
        'id' => 1,
        'full_name' => 'Francesca Inez',
        'parent_id' => 29,
        'active_status' => 1,
        'school_id' => 1,
    ]);
});

function studentImportAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

/* ── The gate ───────────────────────────────────────────────────────── */

it('lets an accountant in', function (): void {
    $this->actingAs(studentImportAuthAs('accountant'))
        ->get(route('admin.contacts.import-students.index'))
        ->assertOk();
});

it('refuses an auditor, who may read contacts but not create them', function (): void {
    $this->actingAs(studentImportAuthAs('auditor'))
        ->get(route('admin.contacts.import-students.index'))
        ->assertForbidden();
});

/* ── Preview writes nothing ─────────────────────────────────────────── */

it('previews without touching the contact register', function (): void {
    $this->actingAs(studentImportAuthAs('accountant'))
        ->post(route('admin.contacts.import-students.preview'))
        ->assertRedirect(route('admin.contacts.import-students.index'));

    expect(Contact::query()->count())->toBe(0)
        ->and(session('student_import.parsed'))->toHaveCount(1);
});

it('shows the student, their payer, and what would happen', function (): void {
    $user = studentImportAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.contacts.import-students.preview'));

    $this->actingAs($user)
        ->get(route('admin.contacts.import-students.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/contacts/import-students', false)
            ->where('parsed.0.student_name', 'Francesca Inez')
            ->where('parsed.0.payer_name', 'Zachary Roy')
            ->where('parsed.0.action', 'create')
            ->where('summary.students', 1)
            ->where('summary.payers', 1)
            ->where('summary.unchanged', 0)
            ->where('summary.errors', 0));
});

it('counts siblings as two students under one new payer', function (): void {
    DB::connection('lms')->table('sm_students')->insert([
        'id' => 2,
        'full_name' => 'Mateo Inez',
        'parent_id' => 29,
        'active_status' => 1,
        'school_id' => 1,
    ]);

    $user = studentImportAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.contacts.import-students.preview'));

    $this->actingAs($user)
        ->get(route('admin.contacts.import-students.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/contacts/import-students', false)
            ->where('summary.students', 2)
            ->where('summary.payers', 1));
});

/* ── Confirm ────────────────────────────────────────────────────────── */

it('imports the student\'s payer and audits the batch', function (): void {
    $user = studentImportAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.contacts.import-students.preview'));
    $token = session('student_import.token');

    $this->actingAs($user)
        ->post(route('admin.contacts.import-students.confirm', $token))
        ->assertRedirect(route('admin.contacts.index'))
        ->assertSessionHas('success', 'Linked 1 student and created 1 new contact.');

    $contact = Contact::query()->sole();

    expect($contact->name)->toBe('Zachary Roy')
        ->and($contact->is_customer)->toBeTrue()
        ->and(ContactStudent::query()->sole()->student_name)->toBe('Francesca Inez')
        ->and(session()->has('student_import'))->toBeFalse();

    $audit = AuditLog::query()
        ->where('action', 'contacts.students_imported')
        ->sole();

    expect($audit->after['contacts_created'])->toBe(1)
        ->and($audit->after['students_linked'])->toBe(1)
        ->and($audit->after['rows_previewed'])->toBe(1);
});

it('rejects a stale preview token', function (): void {
    $user = studentImportAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.contacts.import-students.preview'));

    $this->actingAs($user)
        ->post(route('admin.contacts.import-students.confirm', 'not-the-token'))
        ->assertSessionHasErrors('token');

    expect(Contact::query()->count())->toBe(0);
});
