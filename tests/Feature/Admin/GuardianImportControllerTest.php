<?php

declare(strict_types=1);

use App\Models\Pas\AuditLog;
use App\Models\Pas\Contact;
use App\Models\Pas\ContactStudent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * /admin/contacts/import-guardians.
 *
 * Pinned:
 *  - the gate is AccountingRoles::MANAGE, via ContactPolicy::create
 *  - the preview writes nothing, however many families it finds
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

function guardianImportAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

/* ── The gate ───────────────────────────────────────────────────────── */

it('lets an accountant in', function (): void {
    $this->actingAs(guardianImportAuthAs('accountant'))
        ->get(route('admin.contacts.import-guardians.index'))
        ->assertOk();
});

it('refuses an auditor, who may read contacts but not create them', function (): void {
    $this->actingAs(guardianImportAuthAs('auditor'))
        ->get(route('admin.contacts.import-guardians.index'))
        ->assertForbidden();
});

/* ── Preview writes nothing ─────────────────────────────────────────── */

it('previews without touching the contact register', function (): void {
    $this->actingAs(guardianImportAuthAs('accountant'))
        ->post(route('admin.contacts.import-guardians.preview'))
        ->assertRedirect(route('admin.contacts.import-guardians.index'));

    expect(Contact::query()->count())->toBe(0)
        ->and(session('guardian_import.parsed'))->toHaveCount(1);
});

it('shows the family and what would happen to it', function (): void {
    $user = guardianImportAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.contacts.import-guardians.preview'));

    $this->actingAs($user)
        ->get(route('admin.contacts.import-guardians.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/contacts/import-guardians', false)
            ->where('parsed.0.name', 'Zachary Roy')
            ->where('parsed.0.students.0.name', 'Francesca Inez')
            ->where('parsed.0.action', 'create')
            ->where('summary.create', 1)
            ->where('summary.students', 1));
});

/* ── Confirm ────────────────────────────────────────────────────────── */

it('imports the family and audits the batch', function (): void {
    $user = guardianImportAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.contacts.import-guardians.preview'));
    $token = session('guardian_import.token');

    $this->actingAs($user)
        ->post(route('admin.contacts.import-guardians.confirm', $token))
        ->assertRedirect(route('admin.contacts.index'));

    $contact = Contact::query()->sole();

    expect($contact->name)->toBe('Zachary Roy')
        ->and($contact->is_customer)->toBeTrue()
        ->and(ContactStudent::query()->sole()->student_name)->toBe('Francesca Inez')
        ->and(session()->has('guardian_import'))->toBeFalse();

    $audit = AuditLog::query()
        ->where('action', 'contacts.guardians_imported')
        ->sole();

    expect($audit->after['contacts_created'])->toBe(1)
        ->and($audit->after['students_linked'])->toBe(1);
});

it('rejects a stale preview token', function (): void {
    $user = guardianImportAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.contacts.import-guardians.preview'));

    $this->actingAs($user)
        ->post(route('admin.contacts.import-guardians.confirm', 'not-the-token'))
        ->assertSessionHasErrors('token');

    expect(Contact::query()->count())->toBe(0);
});
