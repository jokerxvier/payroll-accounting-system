<?php

declare(strict_types=1);

use App\Actions\Contacts\ImportLmsGuardians;
use App\Models\Pas\Contact;
use App\Models\Pas\ContactStudent;
use App\Models\Pas\School;
use Illuminate\Support\Facades\DB;

/*
 * Importing parents as billing contacts.
 *
 * The property this whole file exists to hold: **one payer, one contact.** A
 * parent with three children gets one contact and three links, never three
 * copies of the same person — because duplicating a payer scatters a family's
 * receivable across several counterparties, breaks their statement, and counts
 * them repeatedly in Aged Receivables.
 *
 * The live LMS cannot exercise any of this: it holds one student and one
 * parent, so the sibling case is unreachable. These tests run against the
 * sqlite mirror (`useLmsSqliteMirror()`), which is the only place the
 * behaviour can be proven.
 */

beforeEach(function (): void {
    useLmsSqliteMirror();

    ContactStudent::query()->withoutGlobalScopes()->delete();
    Contact::query()->withoutGlobalScopes()->delete();

    DB::connection('lms')->table('sm_students')->delete();
    DB::connection('lms')->table('sm_parents')->delete();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function lmsGuardian(int $id, array $overrides = []): void
{
    DB::connection('lms')->table('sm_parents')->insert([
        'id' => $id,
        'fathers_name' => 'Zachary Roy',
        'fathers_mobile' => '+63 900 000 0000',
        'guardians_name' => 'Zachary Roy',
        'guardians_email' => "guardian{$id}@example.test",
        'guardians_mobile' => '+63 900 000 0000',
        'guardians_relation' => 'Father',
        'guardians_address' => '12 Mabini St',
        'active_status' => 1,
        'school_id' => 1,
        ...$overrides,
    ]);
}

function lmsStudent(int $id, int $parentId, string $name): void
{
    DB::connection('lms')->table('sm_students')->insert([
        'id' => $id,
        'full_name' => $name,
        'parent_id' => $parentId,
        'active_status' => 1,
        'school_id' => 1,
    ]);
}

function importer(): ImportLmsGuardians
{
    return app(ImportLmsGuardians::class);
}

/* ── The rule ───────────────────────────────────────────────────────── */

it('gives siblings one contact and two links, not two contacts', function (): void {
    lmsGuardian(29);
    lmsStudent(1, 29, 'Francesca Inez');
    lmsStudent(2, 29, 'Mateo Inez');

    $rows = importer()->preview();

    // Considered once, with both children beneath.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['students'])->toHaveCount(2)
        ->and($rows[0]['action'])->toBe(ImportLmsGuardians::ACTION_CREATE);

    $result = importer()->apply($rows);

    expect($result['contacts_created'])->toBe(1)
        ->and($result['students_linked'])->toBe(2)
        ->and(Contact::query()->count())->toBe(1)
        ->and(ContactStudent::query()->count())->toBe(2);
});

it('merges two parent rows that are plainly the same person', function (): void {
    // The LMS creates a parent record per admission, so siblings can land on
    // separate rows carrying the same contact details. Importing those as two
    // payers is exactly the duplication this guards against.
    lmsGuardian(29, ['guardians_email' => 'shared@example.test']);
    lmsGuardian(30, ['guardians_email' => 'shared@example.test']);
    lmsStudent(1, 29, 'Francesca Inez');
    lmsStudent(2, 30, 'Mateo Inez');

    importer()->apply(importer()->preview());

    expect(Contact::query()->count())->toBe(1)
        ->and(ContactStudent::query()->count())->toBe(2)
        // The second row claimed the pointer, so the next run matches on the
        // certain key rather than the heuristic.
        ->and(Contact::query()->sole()->lms_parent_id)->toBe(29);
});

it('marks the first payer primary and leaves a second one as a sponsor', function (): void {
    lmsGuardian(29, ['guardians_email' => 'dad@example.test']);
    lmsGuardian(30, ['guardians_email' => 'sponsor@example.test', 'guardians_name' => 'Aunt Ramos']);
    lmsStudent(1, 29, 'Francesca Inez');

    importer()->apply(importer()->preview());

    // A second payer for the same student is linked but never promoted —
    // changing who pays by default is a person's decision, not an import's.
    $contact = Contact::query()->where('lms_parent_id', 29)->sole();
    $link = ContactStudent::query()->forStudent(1)->sole();

    expect($link->contact_id)->toBe($contact->getKey())
        ->and($link->is_primary_payer)->toBeTrue();
});

/* ── Idempotency ────────────────────────────────────────────────────── */

it('changes nothing when run twice', function (): void {
    lmsGuardian(29);
    lmsStudent(1, 29, 'Francesca Inez');

    importer()->apply(importer()->preview());

    $second = importer()->preview();

    expect($second[0]['action'])->toBe(ImportLmsGuardians::ACTION_UNCHANGED);

    $result = importer()->apply($second);

    expect($result['contacts_created'])->toBe(0)
        ->and($result['students_linked'])->toBe(0)
        ->and(Contact::query()->count())->toBe(1)
        ->and(ContactStudent::query()->count())->toBe(1);
});

it('links a newly enrolled sibling to the payer who already exists', function (): void {
    lmsGuardian(29);
    lmsStudent(1, 29, 'Francesca Inez');
    importer()->apply(importer()->preview());

    lmsStudent(2, 29, 'Mateo Inez');

    $rows = importer()->preview();
    expect($rows[0]['action'])->toBe(ImportLmsGuardians::ACTION_LINK);

    importer()->apply($rows);

    expect(Contact::query()->count())->toBe(1)
        ->and(ContactStudent::query()->count())->toBe(2);
});

/* ── Refusals ───────────────────────────────────────────────────────── */

it('refuses to guess when two contacts already share an email', function (): void {
    Contact::factory()->create(['email' => 'shared@example.test', 'code' => 'C1']);
    Contact::factory()->create(['email' => 'shared@example.test', 'code' => 'C2']);

    lmsGuardian(29, ['guardians_email' => 'shared@example.test']);
    lmsStudent(1, 29, 'Francesca Inez');

    $rows = importer()->preview();

    // Merging the wrong two people into one payer is far harder to unpick
    // than importing nothing and saying why.
    expect($rows[0]['errors'])->not->toBeEmpty()
        ->and($rows[0]['errors'][0])->toContain('share the email');

    importer()->apply($rows);

    expect(Contact::query()->count())->toBe(2)
        ->and(ContactStudent::query()->count())->toBe(0);
});

it('refuses a parent record with no name to bill', function (): void {
    lmsGuardian(29, [
        'guardians_name' => null,
        'fathers_name' => null,
        'mothers_name' => null,
    ]);
    lmsStudent(1, 29, 'Francesca Inez');

    $rows = importer()->preview();

    expect($rows[0]['errors'][0])->toContain('nobody to bill');

    importer()->apply($rows);

    expect(Contact::query()->count())->toBe(0);
});

it('skips a student whose parent record has gone', function (): void {
    lmsStudent(1, 999, 'Orphaned Row');

    expect(importer()->preview())->toBeEmpty();
});

it('ignores inactive students', function (): void {
    lmsGuardian(29);
    lmsStudent(1, 29, 'Francesca Inez');
    DB::connection('lms')->table('sm_students')->where('id', 1)->update(['active_status' => 0]);

    expect(importer()->preview())->toBeEmpty();
});

/* ── Tenancy ────────────────────────────────────────────────────────── */

it('never links one school\'s student to another school\'s contact', function (): void {
    $other = School::factory()->create(['slug' => 'other', 'domain' => null]);

    // The same LMS ids exist in every tenant database — student 29 is in both
    // — so a contact carrying lms_parent_id 29 for another school must not be
    // matched here.
    Contact::query()->create([
        'school_id' => $other->getKey(),
        'code' => 'PAR-29',
        'name' => 'Someone Else',
        'is_customer' => true,
        'lms_parent_id' => 29,
    ]);

    lmsGuardian(29);
    lmsStudent(1, 29, 'Francesca Inez');

    importer()->apply(importer()->preview());

    expect(Contact::query()->count())->toBe(1)
        ->and(Contact::query()->withoutGlobalScopes()->count())->toBe(2);
});

/* ── Field mapping ──────────────────────────────────────────────────── */

it('carries the billing details across', function (): void {
    lmsGuardian(29, [
        'guardians_name' => 'Zachary Roy',
        'guardians_email' => 'zach@example.test',
        'guardians_mobile' => '+63 917 111 2222',
        'guardians_address' => '12 Mabini St, Cebu',
        'guardians_relation' => 'Father',
    ]);
    lmsStudent(1, 29, 'Francesca Inez');

    importer()->apply(importer()->preview());

    $contact = Contact::query()->sole();

    expect($contact->name)->toBe('Zachary Roy')
        ->and($contact->email)->toBe('zach@example.test')
        ->and($contact->phone)->toBe('+63 917 111 2222')
        ->and($contact->address)->toBe('12 Mabini St, Cebu')
        ->and($contact->code)->toBe('PAR-29')
        ->and($contact->is_customer)->toBeTrue()
        ->and($contact->is_supplier)->toBeFalse();

    $link = ContactStudent::query()->sole();

    expect($link->student_name)->toBe('Francesca Inez')
        ->and($link->relationship)->toBe('Father');
});

it('falls back to the father when no guardian name is set', function (): void {
    lmsGuardian(29, ['guardians_name' => null, 'fathers_name' => 'Zachary Roy']);
    lmsStudent(1, 29, 'Francesca Inez');

    importer()->apply(importer()->preview());

    expect(Contact::query()->sole()->name)->toBe('Zachary Roy');
});
