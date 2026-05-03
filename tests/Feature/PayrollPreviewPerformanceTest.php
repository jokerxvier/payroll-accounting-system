<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;
use Database\Seeders\StatutoryContributionSeeder;

/*
 * Phase 2 acceptance criterion at `rules/PLAN.md:219` —
 *   "Real-time preview updates within 500ms of input change."
 *
 * This is a server-side budget proxy. The end-to-end "real-time" criterion
 * includes network + render; the dev test environment is local + in-memory
 * SQLite, so the network leg is negligible and server compute is the
 * load-bearing variable. The performance budget at `rules/PLAN.md:336`
 * makes this explicit:
 *   "Single-employee payroll preview: < 500ms server compute".
 *
 * Methodology: discard the first request as a JIT / Eloquent / route-cache
 * warm-up, then take three warm samples. Each sample must clear the 500 ms
 * budget. Three samples guards against a single fast outlier without making
 * the test flaky on shared CI hardware.
 *
 * If a sample exceeds 500 ms, do NOT loosen the budget — the criterion is
 * the contract. A regression here is a P1 to surface before the Phase 2
 * demo, not a number to massage.
 */

function authPerfAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function makePerfProfile(int $basicSalaryCentavos = 4_500_000): EmployeeProfile
{
    /** @var Staff $staff */
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->firstOrFail();

    return EmployeeProfile::factory()->create([
        'lms_staff_id' => (int) $staff->id,
        'is_active' => true,
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => $basicSalaryCentavos,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);
}

it('computes a single-employee preview within the 500ms server budget', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $user = authPerfAs('payroll-officer');
    $profile = makePerfProfile(basicSalaryCentavos: 4_500_000);

    $payload = [
        'lms_staff_id' => $profile->lms_staff_id,
        'frequency' => 'monthly',
        'year' => 2026,
        'month' => 5,
    ];

    // Warm-up: discard. Boots Eloquent, hydrates the route cache, opens the
    // SQLite handle, primes opcache. The "warm cache" the budget calls out.
    $this->actingAs($user)->post('/payroll/preview/compute', $payload)->assertOk();

    // Three warm samples. Each must clear 500 ms independently.
    $samples = [];

    for ($i = 0; $i < 3; $i++) {
        $start = microtime(true);

        $response = $this->actingAs($user)
            ->post('/payroll/preview/compute', $payload);

        $elapsedMs = (microtime(true) - $start) * 1000;

        $response->assertOk();

        $samples[] = $elapsedMs;

        expect($elapsedMs)
            ->toBeLessThan(
                500.0,
                "Sample #{$i} took {$elapsedMs} ms, over the 500 ms budget at rules/PLAN.md:336."
            );
    }

    // Sanity guard: at least one sample must have actually been recorded.
    expect($samples)->toHaveCount(3);
});
