<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Slice 3a — Employee Directory Index controller, FormRequest, policy, route.
 *
 * The controller delegates listing to EloquentEmployeeRepository::paginate(),
 * which queries LMS staff on the read-only `lms` connection (live MySQL in
 * tests) and joins on pas_employee_profiles on the sqlite default connection.
 *
 * Tests log in real users by assigning the relevant Spatie role first.
 */

function authAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

it('requires authentication', function () {
    $this->get('/employees')->assertRedirect('/login');
});

it('forbids the employee role', function () {
    $user = authAs('employee');

    $this->actingAs($user)->get('/employees')->assertForbidden();
});

it('returns paginated employees as Inertia props', function () {
    $user = authAs('super-admin');

    $this->actingAs($user)
        ->get('/employees')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/index')
            ->has('employees')
            ->has('employees.data')
            ->has('filters')
            ->has('departmentOptions')
            ->has('employmentTypeOptions', 4)
        );
});

it('filters by employment_classification', function () {
    $user = authAs('super-admin');

    config(['payroll.employee_role_allowlist' => [1, 4, 5]]);

    // Pick two distinct LMS staff and give each a different classification.
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->limit(2)->get();
    expect($staff)->toHaveCount(2);

    EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $staff[0]->id,
        'employment_classification' => 'regular',
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 5_000_000,
        'is_active' => true,
    ]);
    EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $staff[1]->id,
        'employment_classification' => 'contractual',
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_000_000,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get('/employees?employment_classification=regular')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/index')
            ->where('employees.data', fn ($rows) => collect($rows)
                ->every(fn ($r) => ($r['employment_classification'] ?? null) === 'regular')
            )
        );
});

it('filters by status=inactive', function () {
    $user = authAs('super-admin');

    config(['payroll.employee_role_allowlist' => [1, 4, 5]]);

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();

    /** @var Staff $staff */
    EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $staff->id,
        'employment_classification' => 'regular',
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 5_000_000,
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->get('/employees?status=inactive')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/index')
            ->where('employees.data', fn ($rows) => collect($rows)
                ->every(fn ($r) => ($r['is_active'] ?? null) === false)
            )
        );
});

it('searches by name fragment', function () {
    $user = authAs('super-admin');

    config(['payroll.employee_role_allowlist' => [1, 4, 5]]);

    // Pull a real LMS name fragment from the seeded data and search for it.
    $sample = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($sample)->not->toBeNull();

    $fragment = mb_substr((string) $sample->full_name, 0, 3);

    $this->actingAs($user)
        ->get('/employees?search='.urlencode($fragment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/index')
            ->where('employees.data', fn ($rows) => collect($rows)
                ->every(fn ($r) => str_contains(
                    mb_strtolower((string) ($r['full_name'] ?? '')),
                    mb_strtolower($fragment)
                ))
            )
        );
});

it('rejects invalid filters', function () {
    $user = authAs('super-admin');

    $this->actingAs($user)
        ->get('/employees?status=garbage')
        ->assertSessionHasErrors('status');
});

it('sorts employees by staff_no ascending', function () {
    $user = authAs('super-admin');

    $this->actingAs($user)
        ->get('/employees?sort_by=staff_no&sort_dir=asc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/index')
            ->where('employees.data', function ($rows) {
                $values = collect($rows)
                    ->pluck('staff_no')
                    ->filter(fn ($v) => $v !== null && $v !== '')
                    ->values()
                    ->all();
                $sorted = $values;
                sort($sorted, SORT_NATURAL);

                return $values === $sorted;
            })
        );
});

it('sorts employees by full_name descending', function () {
    $user = authAs('super-admin');

    $this->actingAs($user)
        ->get('/employees?sort_by=full_name&sort_dir=desc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/index')
            ->where('employees.data', function ($rows) {
                $values = collect($rows)
                    ->pluck('full_name')
                    ->filter(fn ($v) => $v !== null && $v !== '')
                    ->values()
                    ->all();

                // MySQL's default collation is case-insensitive. Use strcasecmp
                // to match its ordering rather than PHP's byte comparison.
                for ($i = 1; $i < count($values); $i++) {
                    if (strcasecmp($values[$i - 1], $values[$i]) < 0) {
                        return false;
                    }
                }

                return true;
            })
        );
});

it('defaults sort_dir to asc when sort_by is provided alone', function () {
    $user = authAs('super-admin');

    $this->actingAs($user)
        ->get('/employees?sort_by=staff_no')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('employees/index'));
});

it('rejects an unknown sort_by column', function () {
    $user = authAs('super-admin');

    $this->actingAs($user)
        ->get('/employees?sort_by=garbage_column')
        ->assertSessionHasErrors('sort_by');
});

it('rejects sort_by basic_salary_centavos as a deferred column', function () {
    $user = authAs('super-admin');

    $this->actingAs($user)
        ->get('/employees?sort_by=basic_salary_centavos')
        ->assertSessionHasErrors('sort_by');
});

it('rejects sort_by employment_classification as a deferred column', function () {
    $user = authAs('super-admin');

    $this->actingAs($user)
        ->get('/employees?sort_by=employment_classification')
        ->assertSessionHasErrors('sort_by');
});

it('rejects an invalid sort_dir', function () {
    $user = authAs('super-admin');

    $this->actingAs($user)
        ->get('/employees?sort_by=staff_no&sort_dir=sideways')
        ->assertSessionHasErrors('sort_dir');
});
