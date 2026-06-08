<?php

declare(strict_types=1);

use App\Models\Pas\School;
use App\Models\User;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * /admin/schools resource controller (Phase B.2 backend slice).
 *
 * Pinned behaviors:
 *  - Auth + role gates: platform-admin only (payroll-native users with no
 *    LMS counterpart); every other role + guest is denied. School-scoped
 *    super-admin is now denied too — cross-tenant powers moved to
 *    platform-admin in the multi-tenant role-separation slice.
 *  - Validation: required fields surface as 422 field-level errors; slug
 *    uniqueness enforced; port range enforced; slug regex enforced.
 *  - Update: empty/missing `lms_db_password` keeps the existing credential;
 *    a non-empty value rotates it. The unique rule on slug ignores self.
 *  - Default-school protection: deleting the seeded `default` school returns
 *    403 (Phase D backfill + Phase C fall-through both depend on it).
 *
 * The Inertia component-name assertion uses ', false' to skip the file
 * existence check — TSX pages ship in B.2-frontend.
 */

function schoolAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

/**
 * Build the canonical "platform admin" test user: payroll-native (no LMS
 * counterpart) with the platform-admin Spatie role. Mirrors the seeder
 * payload — both load-bearing gates (lms_user_id IS NULL + role) pass.
 */
function platformAdminUser(): User
{
    $user = User::factory()->withoutLmsMirror()->create([
        'name' => 'Test Platform Admin',
    ]);
    $user->syncRoles(['platform-admin']);

    return $user->fresh() ?? $user;
}

/** @return array<string, mixed> */
function validSchoolPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Acme School',
        'slug' => 'acme-school',
        'domain' => null,
        'lms_db_host' => '127.0.0.1',
        'lms_db_port' => 3306,
        'lms_db_database' => 'lms_acme',
        'lms_db_username' => 'lms_user',
        'lms_db_password' => 'secret-password',
        'lms_db_charset' => 'utf8mb4',
        'is_active' => true,
    ], $overrides);
}

it('allows platform-admin to view the index and lists existing schools', function (): void {
    $user = platformAdminUser();
    School::factory()->count(2)->create();

    $this->actingAs($user)
        ->get('/admin/schools')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->component('admin/schools/index', false),
        );
});

it('renders the seeded default school in the index', function (): void {
    $user = platformAdminUser();
    $this->seed(SchoolSeeder::class);

    $this->actingAs($user)
        ->get('/admin/schools')
        ->assertOk();

    expect(School::query()->where('slug', 'default')->exists())->toBeTrue();
});

it('forbids the index for non-platform-admin roles', function (string $role): void {
    $user = schoolAuthAs($role);

    $this->actingAs($user)
        ->get('/admin/schools')
        ->assertForbidden();
})->with(['super-admin', 'payroll-officer', 'hr', 'auditor', 'employee']);

it('redirects guests from the index to login', function (): void {
    $this->get('/admin/schools')->assertRedirect('/login');
});

it('allows platform-admin to render the create form', function (): void {
    $user = platformAdminUser();

    $this->actingAs($user)
        ->get('/admin/schools/create')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->component('admin/schools/form', false)
                ->where('mode', 'create')
                ->where('school', null),
        );
});

it('forbids the create form for non-platform-admin roles', function (string $role): void {
    $user = schoolAuthAs($role);

    $this->actingAs($user)
        ->get('/admin/schools/create')
        ->assertForbidden();
})->with(['super-admin', 'payroll-officer', 'hr', 'auditor', 'employee']);

it('allows platform-admin to store a valid school', function (): void {
    $user = platformAdminUser();

    $this->actingAs($user)
        ->from('/admin/schools/create')
        ->post('/admin/schools', validSchoolPayload())
        ->assertRedirect('/admin/schools')
        ->assertSessionHas('success');

    expect(School::query()->where('slug', 'acme-school')->exists())->toBeTrue();
});

it('forbids storing a school for non-platform-admin roles', function (string $role): void {
    $user = schoolAuthAs($role);

    $this->actingAs($user)
        ->post('/admin/schools', validSchoolPayload())
        ->assertForbidden();

    expect(School::query()->where('slug', 'acme-school')->exists())->toBeFalse();
})->with(['super-admin', 'payroll-officer', 'hr', 'auditor', 'employee']);

it('rejects a store request with missing required fields', function (): void {
    $user = platformAdminUser();
    // Phase C.1 — global Pest beforeEach seeds the default school for the
    // NeedsTenant middleware. A failed validation must not create a row
    // beyond the seeded default.
    $baseline = School::query()->count();

    $this->actingAs($user)
        ->from('/admin/schools/create')
        ->post('/admin/schools', [])
        ->assertSessionHasErrors([
            'name',
            'slug',
            'lms_db_host',
            'lms_db_port',
            'lms_db_database',
            'lms_db_username',
            'lms_db_password',
            'lms_db_charset',
            'is_active',
        ]);

    expect(School::query()->count())->toBe($baseline);
});

it('rejects a store request with a duplicate slug', function (): void {
    $user = platformAdminUser();
    School::factory()->create(['slug' => 'taken-slug']);

    $this->actingAs($user)
        ->from('/admin/schools/create')
        ->post('/admin/schools', validSchoolPayload(['slug' => 'taken-slug']))
        ->assertSessionHasErrors('slug');
});

it('rejects a store request with a slug that has invalid characters', function (): void {
    $user = platformAdminUser();

    $this->actingAs($user)
        ->from('/admin/schools/create')
        ->post('/admin/schools', validSchoolPayload(['slug' => 'NOT VALID']))
        ->assertSessionHasErrors('slug');
});

it('rejects a store request with an out-of-range port', function (): void {
    $user = platformAdminUser();

    $this->actingAs($user)
        ->from('/admin/schools/create')
        ->post('/admin/schools', validSchoolPayload(['lms_db_port' => 70_000]))
        ->assertSessionHasErrors('lms_db_port');
});

it('allows platform-admin to render the edit form', function (): void {
    $user = platformAdminUser();
    $school = School::factory()->create();

    $this->actingAs($user)
        ->get("/admin/schools/{$school->id}/edit")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->component('admin/schools/form', false)
                ->where('mode', 'edit')
                ->where('school.id', $school->id),
        );
});

it('allows platform-admin to update a school', function (): void {
    $user = platformAdminUser();
    $school = School::factory()->create([
        'name' => 'Original Name',
        'lms_db_password' => 'original-secret',
    ]);

    $this->actingAs($user)
        ->from("/admin/schools/{$school->id}/edit")
        ->put("/admin/schools/{$school->id}", validSchoolPayload([
            'name' => 'Updated Name',
            'slug' => $school->slug,
            'lms_db_password' => '',  // empty -> keep existing
        ]))
        ->assertRedirect('/admin/schools')
        ->assertSessionHas('success');

    $fresh = $school->fresh();
    expect($fresh->name)->toBe('Updated Name')
        ->and($fresh->lms_db_password)->toBe('original-secret');
});

it('rotates the password on update when a non-empty value is supplied', function (): void {
    $user = platformAdminUser();
    $school = School::factory()->create([
        'lms_db_password' => 'original-secret',
    ]);

    $this->actingAs($user)
        ->from("/admin/schools/{$school->id}/edit")
        ->put("/admin/schools/{$school->id}", validSchoolPayload([
            'slug' => $school->slug,
            'lms_db_password' => 'new-secret',
        ]))
        ->assertRedirect('/admin/schools');

    expect($school->fresh()->lms_db_password)->toBe('new-secret');
});

it('allows update validation to ignore the row being edited (slug uniqueness)', function (): void {
    $user = platformAdminUser();
    $school = School::factory()->create(['slug' => 'pinned-slug']);

    $this->actingAs($user)
        ->from("/admin/schools/{$school->id}/edit")
        ->put("/admin/schools/{$school->id}", validSchoolPayload([
            'slug' => 'pinned-slug',  // same as existing
            'lms_db_password' => '',
        ]))
        ->assertRedirect('/admin/schools')
        ->assertSessionHasNoErrors();
});

it('rejects an update with a slug taken by a different school', function (): void {
    $user = platformAdminUser();
    $other = School::factory()->create(['slug' => 'other-school']);
    $school = School::factory()->create(['slug' => 'this-school']);

    $this->actingAs($user)
        ->from("/admin/schools/{$school->id}/edit")
        ->put("/admin/schools/{$school->id}", validSchoolPayload([
            'slug' => 'other-school',
            'lms_db_password' => '',
        ]))
        ->assertSessionHasErrors('slug');

    expect($other->fresh()->slug)->toBe('other-school');
});

it('forbids update for non-platform-admin roles', function (string $role): void {
    $user = schoolAuthAs($role);
    $school = School::factory()->create();

    $this->actingAs($user)
        ->put("/admin/schools/{$school->id}", validSchoolPayload([
            'slug' => $school->slug,
        ]))
        ->assertForbidden();
})->with(['super-admin', 'payroll-officer', 'hr', 'auditor', 'employee']);

it('allows platform-admin to delete a non-default school', function (): void {
    $user = platformAdminUser();
    $school = School::factory()->create(['slug' => 'deletable']);

    $this->actingAs($user)
        ->delete("/admin/schools/{$school->id}")
        ->assertRedirect('/admin/schools')
        ->assertSessionHas('success');

    expect(School::query()->whereKey($school->id)->exists())->toBeFalse();
});

it('forbids deleting the default school even for platform-admin', function (): void {
    $user = platformAdminUser();
    $this->seed(SchoolSeeder::class);
    $default = School::query()->where('slug', 'default')->firstOrFail();

    $this->actingAs($user)
        ->delete("/admin/schools/{$default->id}")
        ->assertForbidden();

    expect(School::query()->whereKey($default->id)->exists())->toBeTrue();
});

it('forbids destroy for non-platform-admin roles', function (string $role): void {
    $user = schoolAuthAs($role);
    $school = School::factory()->create();

    $this->actingAs($user)
        ->delete("/admin/schools/{$school->id}")
        ->assertForbidden();

    expect(School::query()->whereKey($school->id)->exists())->toBeTrue();
})->with(['super-admin', 'payroll-officer', 'hr', 'auditor', 'employee']);

it('redirects guest delete attempts to login', function (): void {
    $school = School::factory()->create();

    $this->delete("/admin/schools/{$school->id}")->assertRedirect('/login');

    expect(School::query()->whereKey($school->id)->exists())->toBeTrue();
});
