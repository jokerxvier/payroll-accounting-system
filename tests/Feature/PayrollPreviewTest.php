<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;
use Database\Seeders\StatutoryContributionSeeder;

/*
 * Phase 2 / Week 8 — Stage A backend for the real-time gross-to-net preview.
 *
 * The preview reuses the Week-7 PayrollComputationService and is therefore a
 * thin Inertia wrapper. These tests cover:
 *
 *   - Route + Inertia component wiring.
 *   - Authorisation — three roles allowed, two denied, plus the unauth case.
 *   - Validation envelope.
 *   - The compute round-trip — current period, prior period, and the
 *     no-profile fallback.
 *   - Period derivation rules from Decision 7 (monthly, semi-monthly first /
 *     second, year=2020 month=1 boundary).
 *   - Wire-shape contract — Money serialisation + audit-line shape, since
 *     the frontend depends on this exact JSON.
 *
 * Statutory rates are seeded per case rather than from the Pest base config —
 * the suite default does not include the SSS / PhilHealth / Pag-IBIG / BIR
 * tables and the engine would short-circuit (or throw) without them.
 */

/**
 * Mirror the helper used in EmployeeIndexTest — override the role allowlist
 * so the LMS staff factory data for the seeded tenant is in scope, then
 * spin up a User with the requested payroll role.
 */
function authPreviewAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/**
 * Convenience builder for a profile that maps onto a real LMS staff row so
 * the FormRequest's `exists:lms.sm_staffs,id` rule is satisfied without us
 * having to mint LMS data.
 */
function makePreviewProfile(int $basicSalaryCentavos = 4_500_000, string $payFrequency = 'monthly'): EmployeeProfile
{
    /** @var Staff $staff */
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->firstOrFail();

    return EmployeeProfile::factory()->create([
        'lms_staff_id' => (int) $staff->id,
        'is_active' => true,
        'pay_frequency' => $payFrequency,
        'basic_salary_centavos' => $basicSalaryCentavos,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);
}

it('allows super-admin to view the preview page', function (): void {
    $user = authPreviewAs('super-admin');

    $this->actingAs($user)
        ->get('/payroll/preview')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payroll/preview', false)
            ->has('frequencyOptions')
            ->where('frequencyOptions.monthly', 'Monthly')
            ->where('frequencyOptions.semi_monthly_first', 'Semi-monthly (1st half)')
            ->where('frequencyOptions.semi_monthly_second', 'Semi-monthly (2nd half)')
            ->has('defaultPeriod')
            ->where('defaultPeriod.frequency', 'monthly')
            ->has('employees')
            ->where('selection', null)
            ->where('currentPeriod', null)
            ->where('priorPeriod', null)
        );
});

it('allows payroll-officer to view the preview page', function (): void {
    $user = authPreviewAs('payroll-officer');

    $this->actingAs($user)
        ->get('/payroll/preview')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('payroll/preview', false));
});

it('allows hr to view the preview page', function (): void {
    $user = authPreviewAs('hr');

    $this->actingAs($user)
        ->get('/payroll/preview')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('payroll/preview', false));
});

it('forbids the auditor role from viewing the preview', function (): void {
    $user = authPreviewAs('auditor');

    $this->actingAs($user)
        ->get('/payroll/preview')
        ->assertForbidden();
});

it('forbids the employee role from viewing the preview', function (): void {
    $user = authPreviewAs('employee');

    $this->actingAs($user)
        ->get('/payroll/preview')
        ->assertForbidden();
});

it('forbids unauthenticated users from viewing the preview', function (): void {
    $this->get('/payroll/preview')->assertRedirect('/login');
});

it('computes a preview for a valid employee in a monthly period', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $user = authPreviewAs('payroll-officer');
    $profile = makePreviewProfile(basicSalaryCentavos: 4_500_000); // ReferenceCases case 2 figures.

    $this->actingAs($user)
        ->post('/payroll/preview/compute', [
            'lms_staff_id' => $profile->lms_staff_id,
            'frequency' => 'monthly',
            'year' => 2026,
            'month' => 5,
        ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payroll/preview', false)
            ->where('noProfile', false)
            ->has('currentPeriod')
            ->where('currentPeriod.basicPay.centavos', 4_500_000)
            ->where('currentPeriod.basicPay.formatted', '45000.00')
            ->where('currentPeriod.netPay.centavos', 3_833_160)
            ->where('currentPeriod.period.frequency', 'monthly')
            ->where('currentPeriod.period.start', '2026-05-01')
            ->where('currentPeriod.period.end', '2026-05-31')
            ->where('selection.lms_staff_id', $profile->lms_staff_id)
            ->where('selection.frequency', 'monthly')
        );
});

it('includes the prior period when one exists', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $user = authPreviewAs('payroll-officer');
    $profile = makePreviewProfile();

    $this->actingAs($user)
        ->post('/payroll/preview/compute', [
            'lms_staff_id' => $profile->lms_staff_id,
            'frequency' => 'monthly',
            'year' => 2026,
            'month' => 2,
        ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('priorPeriod')
            ->where('priorPeriod.period.frequency', 'monthly')
            ->where('priorPeriod.period.start', '2026-01-01')
            ->where('priorPeriod.period.end', '2026-01-31')
        );
});

it('returns the no-profile flag when the selected staff has no payroll profile', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $user = authPreviewAs('payroll-officer');

    /** @var Staff $staff */
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->firstOrFail();

    // Confirm there is no profile row for the chosen staff up-front.
    expect(EmployeeProfile::query()->where('lms_staff_id', $staff->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->post('/payroll/preview/compute', [
            'lms_staff_id' => (int) $staff->id,
            'frequency' => 'monthly',
            'year' => 2026,
            'month' => 5,
        ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payroll/preview', false)
            ->where('noProfile', true)
            ->where('currentPeriod', null)
            ->where('priorPeriod', null)
            ->where('selection.lms_staff_id', (int) $staff->id)
        );
});

it('rejects compute requests missing required fields', function (): void {
    $user = authPreviewAs('payroll-officer');

    $this->actingAs($user)
        ->post('/payroll/preview/compute', [])
        ->assertSessionHasErrors(['lms_staff_id', 'frequency', 'year', 'month']);
});

it('rejects compute requests with an unknown frequency', function (): void {
    $user = authPreviewAs('payroll-officer');

    /** @var Staff $staff */
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->firstOrFail();

    $this->actingAs($user)
        ->post('/payroll/preview/compute', [
            'lms_staff_id' => (int) $staff->id,
            'frequency' => 'weekly',
            'year' => 2026,
            'month' => 5,
        ])
        ->assertSessionHasErrors('frequency');
});

it('rejects compute requests with an out-of-range month', function (): void {
    $user = authPreviewAs('payroll-officer');

    /** @var Staff $staff */
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->firstOrFail();

    $this->actingAs($user)
        ->post('/payroll/preview/compute', [
            'lms_staff_id' => (int) $staff->id,
            'frequency' => 'monthly',
            'year' => 2026,
            'month' => 13,
        ])
        ->assertSessionHasErrors('month');
});

it('derives the prior period correctly for semi_monthly_first', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $user = authPreviewAs('payroll-officer');
    $profile = makePreviewProfile(payFrequency: 'semi_monthly');

    // Selecting the 1st half of May → prior is the 2nd half of April.
    $this->actingAs($user)
        ->post('/payroll/preview/compute', [
            'lms_staff_id' => $profile->lms_staff_id,
            'frequency' => 'semi_monthly_first',
            'year' => 2026,
            'month' => 5,
        ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currentPeriod.period.start', '2026-05-01')
            ->where('currentPeriod.period.end', '2026-05-15')
            ->where('priorPeriod.period.start', '2026-04-16')
            ->where('priorPeriod.period.end', '2026-04-30')
        );
});

it('derives the prior period correctly for semi_monthly_second', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $user = authPreviewAs('payroll-officer');
    $profile = makePreviewProfile(payFrequency: 'semi_monthly');

    // Selecting the 2nd half of May → prior is the 1st half of the same month.
    $this->actingAs($user)
        ->post('/payroll/preview/compute', [
            'lms_staff_id' => $profile->lms_staff_id,
            'frequency' => 'semi_monthly_second',
            'year' => 2026,
            'month' => 5,
        ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currentPeriod.period.start', '2026-05-16')
            ->where('currentPeriod.period.end', '2026-05-31')
            ->where('priorPeriod.period.start', '2026-05-01')
            ->where('priorPeriod.period.end', '2026-05-15')
        );
});

it('returns null priorPeriod when the selected period is at the floor', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $user = authPreviewAs('payroll-officer');
    $profile = makePreviewProfile();

    // Year=2020, month=1 — the period before this is 2019-12, which is below
    // the seeded statutory rate floor of 2020. The controller short-circuits
    // to null rather than computing against missing rates.
    $this->actingAs($user)
        ->post('/payroll/preview/compute', [
            'lms_staff_id' => $profile->lms_staff_id,
            'frequency' => 'monthly',
            'year' => 2020,
            'month' => 1,
        ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('priorPeriod', null)
        );
});

it('serializes audit lines with a stable wire shape', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $user = authPreviewAs('payroll-officer');
    $profile = makePreviewProfile(basicSalaryCentavos: 4_500_000);

    $this->actingAs($user)
        ->post('/payroll/preview/compute', [
            'lms_staff_id' => $profile->lms_staff_id,
            'frequency' => 'monthly',
            'year' => 2026,
            'month' => 5,
        ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // First audit line is always basic pay (canonical order, see
            // PayrollComputationService::compute()).
            ->where('currentPeriod.auditLines.0.code', 'BASIC_PAY')
            ->where('currentPeriod.auditLines.0.bucket', 'earning')
            ->where('currentPeriod.auditLines.0.amount.centavos', 4_500_000)
            ->where('currentPeriod.auditLines.0.amount.formatted', '45000.00')
            // Second is SSS employee deduction.
            ->where('currentPeriod.auditLines.1.code', 'SSS_EMPLOYEE')
            ->where('currentPeriod.auditLines.1.bucket', 'employee_deduction')
            ->where('currentPeriod.auditLines.1.amount.centavos', 175_000)
            // Meta carries strategy diagnostics for the row that has them.
            // For ₱45,000 the SSS basis is the top-band MSC ceiling (₱35,000),
            // not the raw monthly salary — the strategy applies the cap before
            // emitting the contribution_base_centavos meta.
            ->where('currentPeriod.auditLines.1.meta.contribution_base_centavos', 3_500_000)
        );
});

it('exposes a compact employee option list capped at fifty', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $user = authPreviewAs('super-admin');

    /** @var Staff $staff */
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->firstOrFail();

    EmployeeProfile::factory()->create([
        'lms_staff_id' => (int) $staff->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get('/payroll/preview')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees')
            ->where('employees', function ($rows) use ($staff): bool {
                $list = collect($rows);

                if ($list->count() > 50) {
                    return false;
                }

                $found = $list->firstWhere('lms_staff_id', (int) $staff->id);

                return $found !== null
                    && ($found['has_profile'] ?? null) === true
                    && is_string($found['full_name'] ?? null);
            })
        );
});
