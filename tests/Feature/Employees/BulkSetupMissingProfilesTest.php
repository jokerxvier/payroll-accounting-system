<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Multitenancy\Models\Tenant;

uses(RefreshDatabase::class);

/*
 * Bulk "Set up missing profiles" affordance on /employees —
 * POST /employees/bulk-setup-profiles. Creates a default payroll
 * profile (₱0 salary, regular, monthly, active) for every allowlisted
 * LMS staff in the current tenant that doesn't have one yet.
 *
 * NOTE: like SetupEmployeeProfileTest, these tests rely on the dev MySQL
 * `payroll_db` having `sm_staffs` rows for roles 1, 4, 5. When LMS
 * fixtures are absent (fresh CI without the LMS dump), allowlistedStaffCount()
 * returns 0 and the data-dependent tests skip — they're not assertions
 * about the controller wiring, they're assertions about the bulk loop's
 * behaviour against a populated staff table. Authorization tests run
 * unconditionally because they don't require staff rows to exist.
 */

function authBulkAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function allowlistedStaffCount(): int
{
    return Staff::query()->whereIn('role_id', [1, 4, 5])->count();
}

it('bulk-creates profiles for every no-profile staff in the current tenant', function (): void {
    $user = authBulkAs('payroll-officer');

    $staffCount = allowlistedStaffCount();
    if ($staffCount === 0) {
        $this->markTestSkipped('LMS fixture data missing — no allowlisted sm_staffs rows in dev DB.');
    }

    // Sanity: no profiles exist yet under the default tenant.
    expect(EmployeeProfile::query()->count())->toBe(0);

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/bulk-setup-profiles')
        ->assertRedirect('/employees')
        ->assertSessionHas('success');

    // Every allowlisted staff should now have a profile under the
    // default tenant (the global Pest beforeEach makes default current).
    expect(EmployeeProfile::query()->count())->toBe($staffCount);

    // Every created profile carries the bulk defaults.
    EmployeeProfile::query()->get()->each(function (EmployeeProfile $profile): void {
        expect((int) $profile->basic_salary_centavos)->toBe(0)
            ->and($profile->employment_classification)->toBe('regular')
            ->and($profile->pay_frequency)->toBe('monthly')
            ->and($profile->is_active)->toBeTrue();
    });
});

it('is idempotent — a second call after every staff has a profile is a no-op', function (): void {
    $user = authBulkAs('hr');

    $staffCount = allowlistedStaffCount();
    if ($staffCount === 0) {
        $this->markTestSkipped('LMS fixture data missing — no allowlisted sm_staffs rows in dev DB.');
    }

    // First call — creates profiles.
    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/bulk-setup-profiles')
        ->assertRedirect('/employees');

    $afterFirst = EmployeeProfile::query()->count();
    expect($afterFirst)->toBe($staffCount);

    // Second call — no new rows because firstOrCreate keys on lms_staff_id.
    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/bulk-setup-profiles')
        ->assertRedirect('/employees')
        ->assertSessionHas('success', 'All staff already have payroll profiles.');

    expect(EmployeeProfile::query()->count())->toBe($afterFirst);
});

it('is tenant-scoped — a bulk run under tenant A does not touch tenant B staff profiles', function (): void {
    $user = authBulkAs('super-admin');

    $staffCount = allowlistedStaffCount();
    if ($staffCount === 0) {
        $this->markTestSkipped('LMS fixture data missing — no allowlisted sm_staffs rows in dev DB.');
    }

    // Create a second tenant and pre-seed a profile for a single staff
    // under it. The bulk run for tenant A must NOT delete or duplicate
    // tenant B's profile (separate (school_id, lms_staff_id) rows are
    // allowed because school_id is part of the uniqueness story).
    $other = School::factory()->create(['slug' => 'bulk-other']);
    $other->makeCurrent();

    $someStaffId = (int) Staff::query()->whereIn('role_id', [1, 4, 5])->first()?->id;
    expect($someStaffId)->not->toBe(0);

    EmployeeProfile::factory()->create([
        'school_id' => $other->getKey(),
        'lms_staff_id' => $someStaffId,
        'basic_salary_centavos' => 12_345_600,
    ]);

    // Switch back to default and run the bulk action.
    $default = School::query()->where('slug', 'default')->first();
    $default->makeCurrent();

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/bulk-setup-profiles')
        ->assertRedirect('/employees');

    // Default tenant — every allowlisted staff has a profile.
    expect(EmployeeProfile::query()->count())->toBe($staffCount);

    // Other tenant's pre-seeded profile is untouched. Use
    // withoutGlobalScopes so the assertion reads cross-tenant.
    $otherProfile = EmployeeProfile::query()
        ->withoutGlobalScopes()
        ->where('school_id', $other->getKey())
        ->where('lms_staff_id', $someStaffId)
        ->firstOrFail();

    expect((int) $otherProfile->basic_salary_centavos)->toBe(12_345_600);
});

it('all created profiles carry the current tenant school_id', function (): void {
    $user = authBulkAs('super-admin');

    $staffCount = allowlistedStaffCount();
    if ($staffCount === 0) {
        $this->markTestSkipped('LMS fixture data missing — no allowlisted sm_staffs rows in dev DB.');
    }

    // Switch to a fresh tenant before posting so the BelongsToTenant
    // trait fills its school_id, not the default's.
    $other = School::factory()->create(['slug' => 'bulk-school-id']);
    $other->makeCurrent();

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/bulk-setup-profiles')
        ->assertRedirect('/employees');

    // Every created profile carries `$other->getKey()`. Use
    // withoutGlobalScopes so we read the rows directly without the
    // tenant-aware scope re-applying.
    $profiles = EmployeeProfile::query()
        ->withoutGlobalScopes()
        ->where('school_id', $other->getKey())
        ->get();

    expect($profiles)->toHaveCount($staffCount);

    $profiles->each(function (EmployeeProfile $profile) use ($other): void {
        expect($profile->school_id)->toBe($other->getKey());
    });
});

it('forbids the auditor role on bulk setup', function (): void {
    $user = authBulkAs('auditor');

    $this->actingAs($user)
        ->post('/employees/bulk-setup-profiles')
        ->assertForbidden();

    expect(EmployeeProfile::query()->count())->toBe(0);
});

it('forbids the employee role on bulk setup', function (): void {
    $user = authBulkAs('employee');

    $this->actingAs($user)
        ->post('/employees/bulk-setup-profiles')
        ->assertForbidden();

    expect(EmployeeProfile::query()->count())->toBe(0);
});

it('allows the write-capable roles', function (string $role): void {
    $user = authBulkAs($role);

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/bulk-setup-profiles')
        ->assertRedirect('/employees');
})->with(['super-admin', 'payroll-officer', 'hr']);

afterEach(function (): void {
    // Reset the tenant binding the test may have switched away from so
    // the next test's beforeEach starts from a clean slate. The global
    // Pest beforeEach re-binds default afterwards.
    Tenant::forgetCurrent();
});
