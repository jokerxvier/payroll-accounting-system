<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * These tests use RefreshDatabase on the default test connection (sqlite in-memory,
 * configured in phpunit.xml). Migrations only touch `pas_*` tables, all of which
 * are app-owned and sqlite-compatible.
 *
 * The `lms` connection is configured separately in config/database.php and points
 * at the live MySQL database used for LMS identity. We READ from it but never
 * write to it (LmsReadOnlyTest enforces that, so do these tests).
 */
uses(RefreshDatabase::class);

it('returns only allowlisted-role staff in paginate()', function () {
    // The seeded LMS DB has staff with role_ids in [1, 4, 5] (Super admin, Teacher, Admin).
    // Restricting the allowlist to a single role must filter the rest out.
    $targetRoleId = 4; // Teacher (13 staff observed)

    config(['payroll.employee_role_allowlist' => [$targetRoleId]]);

    $repo = app(EmployeeRepositoryInterface::class);

    $page = $repo->paginate(perPage: 100);

    expect($page->total())->toBeGreaterThan(0);

    foreach ($page->items() as $row) {
        expect((int) $row->role_id)->toBe($targetRoleId);
    }

    // Sanity check: the paginator total matches a direct LMS count for that role.
    $expected = Staff::query()->where('role_id', $targetRoleId)->count();
    expect($page->total())->toBe($expected);
});

it('honors profile-level filters without dropping the page total', function () {
    config(['payroll.employee_role_allowlist' => [1, 4, 5]]);

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();

    /** @var Staff $staff */
    EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $staff->id,
        'employment_classification' => 'regular',
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 5_000_000,
        'is_active' => true,
    ]);

    $repo = app(EmployeeRepositoryInterface::class);

    $filtered = $repo->paginate(['employment_classification' => 'regular'], perPage: 100);

    foreach ($filtered->items() as $row) {
        expect($row->has_profile)->toBeTrue()
            ->and($row->employment_classification)->toBe('regular');
    }
});

it('never writes to LMS tables when calling firstOrCreateForStaff', function () {
    config(['payroll.employee_role_allowlist' => [1, 4, 5]]);

    $staffCountBefore = (int) DB::connection('lms')->table('sm_staffs')->count();
    $usersCountBefore = (int) DB::connection('lms')->table('users')->count();
    $rolesCountBefore = (int) DB::connection('lms')->table('roles')->count();

    $staff = Staff::query()->first();
    expect($staff)->not->toBeNull();

    /** @var Staff $staff */
    $repo = app(EmployeeRepositoryInterface::class);

    $profile = $repo->firstOrCreateForStaff((int) $staff->id, [
        'employment_classification' => 'regular',
        'pay_frequency' => 'semi_monthly',
        'basic_salary_centavos' => 0,
    ]);

    expect($profile)->toBeInstanceOf(EmployeeProfile::class)
        ->and($profile->lms_staff_id)->toBe((int) $staff->id);

    // Calling again must not create a duplicate profile and must not touch LMS.
    $again = $repo->firstOrCreateForStaff((int) $staff->id);
    expect($again->id)->toBe($profile->id);

    expect((int) DB::connection('lms')->table('sm_staffs')->count())->toBe($staffCountBefore);
    expect((int) DB::connection('lms')->table('users')->count())->toBe($usersCountBefore);
    expect((int) DB::connection('lms')->table('roles')->count())->toBe($rolesCountBefore);
});

it('round-trips encrypted sensitive fields', function () {
    $staff = Staff::query()->first();
    expect($staff)->not->toBeNull();

    /** @var Staff $staff */
    $profile = EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $staff->id,
        'tin' => '123-456-789-000',
        'sss_number' => '34-1234567-8',
        'philhealth_number' => '12-345678901-2',
        'pagibig_number' => '1234-5678-9012',
        'bank_account_number' => '0001234567890',
        'bank_name' => 'BPI',
        'bank_account_name' => 'Juan Dela Cruz',
    ]);

    $reloaded = EmployeeProfile::query()->findOrFail($profile->id);

    expect($reloaded->tin)->toBe('123-456-789-000')
        ->and($reloaded->sss_number)->toBe('34-1234567-8')
        ->and($reloaded->philhealth_number)->toBe('12-345678901-2')
        ->and($reloaded->pagibig_number)->toBe('1234-5678-9012')
        ->and($reloaded->bank_account_number)->toBe('0001234567890')
        ->and($reloaded->bank_name)->toBe('BPI')
        ->and($reloaded->bank_account_name)->toBe('Juan Dela Cruz');

    // Confirm at the raw DB level that the ciphertext is not the plaintext.
    $raw = DB::table('pas_employee_profiles')->where('id', $profile->id)->first();
    expect($raw)->not->toBeNull()
        ->and($raw->tin)->not->toBe('123-456-789-000')
        ->and($raw->sss_number)->not->toBe('34-1234567-8')
        ->and($raw->bank_account_number)->not->toBe('0001234567890');
});

it('stores basic_salary_centavos as an exact integer', function () {
    $staff = Staff::query()->first();
    expect($staff)->not->toBeNull();

    /** @var Staff $staff */
    $profile = EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $staff->id,
        'basic_salary_centavos' => 1_234_567,
    ]);

    $reloaded = EmployeeProfile::query()->findOrFail($profile->id);

    expect($reloaded->basic_salary_centavos)->toBe(1_234_567)
        ->and($reloaded->basic_salary_centavos)->toBeInt();

    // Raw DB value must also be the exact integer (no decimal coercion).
    $raw = DB::table('pas_employee_profiles')->where('id', $profile->id)->first();
    expect((int) $raw->basic_salary_centavos)->toBe(1_234_567);
});
