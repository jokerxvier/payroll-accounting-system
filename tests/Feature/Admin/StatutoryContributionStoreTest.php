<?php

declare(strict_types=1);

use App\Models\Pas\AuditLog;
use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use App\Services\Statutory\StatutoryContributionResolver;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * POST /admin/contribution-tables
 *
 * Pinned behaviors:
 *  - Auth + role gates: only super-admin may store.
 *  - Pure-insert path: when no row exists for the code, the new row is
 *    inserted with effective_to=null (no supersession needed).
 *  - Supersede path: when an open-ended row exists, it must be closed off
 *    with effective_to = new row's effective_from (the boundary invariant
 *    scopeForDate relies on).
 *  - Validation: effective_from must be strictly after the latest existing
 *    effective_from for the same code; the rules JSON shape must satisfy
 *    the chosen algorithm's strategy.
 *  - Audit: each successful store fires one `created` row, and (when
 *    superseding) one `updated` row on the previous open-ended row.
 *  - LMS guardrail: counts of `users`, `sm_staffs`, `roles`,
 *    `sm_human_departments`, `sm_designations` are unchanged.
 */

function storeAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function validBirPayload(string $effectiveFrom = '2026-01-01'): array
{
    return [
        'contribution_code' => StatutoryContribution::CODE_BIR,
        'label' => 'BIR Withholding Tax',
        'algorithm' => StatutoryContribution::ALGORITHM_BRACKET_TABLE,
        'effective_from' => $effectiveFrom,
        'rules' => [
            'period_types' => [
                'monthly' => [
                    ['lower' => 0, 'upper' => 2_083_300, 'base_tax' => 0, 'excess_rate_bp' => 0, 'excess_over' => 0],
                    ['lower' => 2_083_300, 'upper' => 3_333_300, 'base_tax' => 0, 'excess_rate_bp' => 1500, 'excess_over' => 2_083_300],
                    ['lower' => 3_333_300, 'upper' => null, 'base_tax' => 187_500, 'excess_rate_bp' => 2000, 'excess_over' => 3_333_300],
                ],
            ],
        ],
        'notes' => null,
    ];
}

function validSssPayload(string $effectiveFrom = '2026-01-01'): array
{
    return [
        'contribution_code' => StatutoryContribution::CODE_SSS,
        'label' => 'SSS contribution',
        'algorithm' => StatutoryContribution::ALGORITHM_SALARY_BAND,
        'effective_from' => $effectiveFrom,
        'rules' => [
            'bands' => [
                [
                    'lower' => 0,
                    'upper' => 525_000,
                    'contribution_base' => 500_000,
                    'employee_share' => 22_500,
                    'employer_share' => 47_500,
                    'employer_aux_share' => 1_000,
                    'employee_aux_share' => 0,
                ],
                [
                    'lower' => 525_000,
                    'upper' => null,
                    'contribution_base' => 1_500_000,
                    'employee_share' => 67_500,
                    'employer_share' => 142_500,
                    'employer_aux_share' => 1_000,
                    'employee_aux_share' => 0,
                ],
            ],
        ],
        'notes' => null,
    ];
}

it('redirects unauthenticated users to the login screen on store', function () {
    $this->post('/admin/contribution-tables', validBirPayload())
        ->assertRedirect('/login');
});

it('allows the payroll-officer role on store', function () {
    $user = storeAuthAs('payroll-officer');

    $this->actingAs($user)
        ->post('/admin/contribution-tables', validBirPayload())
        ->assertRedirect('/admin/contribution-tables');
});

it('allows the hr role on store', function () {
    $user = storeAuthAs('hr');

    $this->actingAs($user)
        ->post('/admin/contribution-tables', validBirPayload())
        ->assertRedirect('/admin/contribution-tables');
});

it('forbids the auditor role on store', function () {
    $user = storeAuthAs('auditor');

    $this->actingAs($user)
        ->post('/admin/contribution-tables', validBirPayload())
        ->assertForbidden();
});

it('forbids the employee role on store', function () {
    $user = storeAuthAs('employee');

    $this->actingAs($user)
        ->post('/admin/contribution-tables', validBirPayload())
        ->assertForbidden();
});

it('inserts a new row when no prior version exists for the code', function () {
    $user = storeAuthAs('super-admin');

    expect(StatutoryContribution::query()->count())->toBe(0);

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', validBirPayload())
        ->assertRedirect('/admin/contribution-tables');

    expect(StatutoryContribution::query()->count())->toBe(1);

    $row = StatutoryContribution::query()->first();
    expect($row->contribution_code)->toBe(StatutoryContribution::CODE_BIR)
        ->and($row->algorithm)->toBe(StatutoryContribution::ALGORITHM_BRACKET_TABLE)
        ->and($row->effective_from->toDateString())->toBe('2026-01-01')
        ->and($row->effective_to)->toBeNull();
});

it('supersedes the prior open-ended row when storing a new version', function () {
    $user = storeAuthAs('super-admin');

    $existing = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', validSssPayload('2026-01-01'))
        ->assertRedirect('/admin/contribution-tables');

    $existing->refresh();

    expect($existing->effective_to)->not->toBeNull()
        ->and($existing->effective_to->toDateString())->toBe('2026-01-01');

    $rows = StatutoryContribution::query()
        ->where('contribution_code', StatutoryContribution::CODE_SSS)
        ->orderBy('effective_from')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->id)->toBe($existing->id)
        ->and($rows[0]->effective_to->toDateString())->toBe('2026-01-01')
        ->and($rows[1]->effective_from->toDateString())->toBe('2026-01-01')
        ->and($rows[1]->effective_to)->toBeNull();
});

it('rejects an effective_from equal to the latest existing version', function () {
    $user = storeAuthAs('super-admin');

    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2025-01-01',
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', validSssPayload('2025-01-01'))
        ->assertSessionHasErrors('effective_from');

    expect(StatutoryContribution::query()->where('contribution_code', StatutoryContribution::CODE_SSS)->count())->toBe(1);
});

it('rejects an effective_from earlier than the latest existing version', function () {
    $user = storeAuthAs('super-admin');

    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2025-01-01',
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', validSssPayload('2024-06-01'))
        ->assertSessionHasErrors('effective_from');

    expect(StatutoryContribution::query()->where('contribution_code', StatutoryContribution::CODE_SSS)->count())->toBe(1);
});

it('persists a custom contribution code that is not in the recommended list', function () {
    $user = storeAuthAs('super-admin');

    expect(StatutoryContribution::query()->count())->toBe(0);

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', [
            'contribution_code' => 'MAKATI_TAX',
            'label' => 'Makati Local Tax',
            'algorithm' => StatutoryContribution::ALGORITHM_FLAT_PERCENTAGE,
            'application_order' => 75,
            'applies_to' => StatutoryContribution::APPLIES_TO_GROSS_PAY,
            'effective_from' => '2026-01-01',
            'rules' => [
                'rate_bp' => 100,
                'employee_share_bp' => 10_000,
                'employer_share_bp' => 0,
                'cap' => null,
            ],
            'notes' => null,
        ])
        ->assertRedirect('/admin/contribution-tables');

    $row = StatutoryContribution::query()->firstOrFail();

    expect($row->contribution_code)->toBe('MAKATI_TAX')
        ->and($row->label)->toBe('Makati Local Tax')
        ->and($row->algorithm)->toBe(StatutoryContribution::ALGORITHM_FLAT_PERCENTAGE)
        ->and($row->effective_from->toDateString())->toBe('2026-01-01')
        ->and($row->effective_to)->toBeNull();
});

it('rejects malformed contribution codes', function (string $badCode): void {
    $user = storeAuthAs('super-admin');

    $payload = validFlatPercentageBpPayload();
    $payload['contribution_code'] = $badCode;

    $response = $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', $payload);

    $response->assertStatus(302)
        ->assertSessionHasErrors('contribution_code');

    $errors = session('errors')->get('contribution_code');

    expect($errors[0])->toContain('Code must be uppercase letters');
})->with([
    'lowercase' => ['city_tax'],
    'leading digit' => ['1TAX'],
    'too short' => ['AB'],
    'too long' => [str_repeat('A', 33)],
    'special chars' => ['MY-TAX'],
    'spaces' => ['MY TAX'],
]);

it('rejects an unknown algorithm', function () {
    $user = storeAuthAs('super-admin');

    $payload = validBirPayload();
    $payload['algorithm'] = 'something_made_up';

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', $payload)
        ->assertSessionHasErrors('algorithm');
});

it('rejects bracket_table rules with an empty period_types', function () {
    $user = storeAuthAs('super-admin');

    $payload = validBirPayload();
    $payload['rules'] = ['period_types' => []];

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', $payload)
        ->assertSessionHasErrors('rules');
});

it('rejects salary_band rules with an empty bands array', function () {
    $user = storeAuthAs('super-admin');

    $payload = validSssPayload();
    $payload['rules'] = ['bands' => []];

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', $payload)
        ->assertSessionHasErrors('rules');
});

it('rejects percentage_with_cap rules with floor > ceiling', function () {
    $user = storeAuthAs('super-admin');

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', [
            'contribution_code' => StatutoryContribution::CODE_PHILHEALTH,
            'label' => 'PhilHealth premium',
            'algorithm' => StatutoryContribution::ALGORITHM_PERCENTAGE_WITH_CAP,
            'effective_from' => '2026-01-01',
            'rules' => [
                'rate_bp' => 500,
                'floor' => 50_000_000,
                'ceiling' => 1_000_000,
                'split' => ['employee_bp' => 5_000, 'employer_bp' => 5_000],
            ],
        ])
        ->assertSessionHasErrors('rules');
});

it('rejects tiered_percentage rules with threshold > max_msc', function () {
    $user = storeAuthAs('super-admin');

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', [
            'contribution_code' => StatutoryContribution::CODE_PAGIBIG,
            'label' => 'Pag-IBIG contribution',
            'algorithm' => StatutoryContribution::ALGORITHM_TIERED_PERCENTAGE,
            'effective_from' => '2026-01-01',
            'rules' => [
                'threshold' => 1_000_000,
                'max_msc' => 500_000,
                'lower' => ['ee_bp' => 100, 'er_bp' => 200],
                'upper' => ['ee_bp' => 200, 'er_bp' => 200],
            ],
        ])
        ->assertSessionHasErrors('rules');
});

it('writes one created audit row when the first version of a code is inserted', function () {
    $user = storeAuthAs('super-admin');

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', validBirPayload())
        ->assertRedirect('/admin/contribution-tables');

    $row = StatutoryContribution::query()->firstOrFail();

    $audits = AuditLog::query()
        ->where('auditable_type', StatutoryContribution::class)
        ->where('auditable_id', $row->id)
        ->get();

    expect($audits)->toHaveCount(1)
        ->and($audits->first()->action)->toBe('created')
        ->and($audits->first()->actor_id)->toBe($user->id);
});

it('writes both created and updated audit rows on supersession', function () {
    $user = storeAuthAs('super-admin');

    $existing = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $existingCreatedAuditCount = AuditLog::query()
        ->where('auditable_type', StatutoryContribution::class)
        ->where('auditable_id', $existing->id)
        ->where('action', 'created')
        ->count();

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', validSssPayload('2026-01-01'))
        ->assertRedirect('/admin/contribution-tables');

    $newRow = StatutoryContribution::query()
        ->where('contribution_code', StatutoryContribution::CODE_SSS)
        ->whereNull('effective_to')
        ->firstOrFail();

    // The new row gets one `created` audit row.
    $newCreated = AuditLog::query()
        ->where('auditable_type', StatutoryContribution::class)
        ->where('auditable_id', $newRow->id)
        ->where('action', 'created')
        ->get();

    expect($newCreated)->toHaveCount(1)
        ->and($newCreated->first()->actor_id)->toBe($user->id);

    // The existing row gets one `updated` audit row capturing the
    // effective_to transition from null -> 2026-01-01.
    $existingUpdated = AuditLog::query()
        ->where('auditable_type', StatutoryContribution::class)
        ->where('auditable_id', $existing->id)
        ->where('action', 'updated')
        ->get();

    expect($existingUpdated)->toHaveCount(1)
        ->and($existingUpdated->first()->actor_id)->toBe($user->id);

    $beforeKeys = array_keys($existingUpdated->first()->before);
    $afterKeys = array_keys($existingUpdated->first()->after);

    expect($beforeKeys)->toContain('effective_to')
        ->and($afterKeys)->toContain('effective_to');

    // The pre-existing `created` row on the outgoing model is untouched.
    $stillThere = AuditLog::query()
        ->where('auditable_type', StatutoryContribution::class)
        ->where('auditable_id', $existing->id)
        ->where('action', 'created')
        ->count();

    expect($stillThere)->toBe($existingCreatedAuditCount);
});

it('does not mutate any LMS-owned table when storing a new version', function () {
    $user = storeAuthAs('super-admin');

    $usersCount = (int) DB::connection('lms')->table('users')->count();
    $staffsCount = (int) DB::connection('lms')->table('sm_staffs')->count();
    $rolesCount = (int) DB::connection('lms')->table('roles')->count();
    $deptCount = (int) DB::connection('lms')->table('sm_human_departments')->count();
    $desigCount = (int) DB::connection('lms')->table('sm_designations')->count();

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', validBirPayload())
        ->assertRedirect('/admin/contribution-tables');

    expect((int) DB::connection('lms')->table('users')->count())->toBe($usersCount)
        ->and((int) DB::connection('lms')->table('sm_staffs')->count())->toBe($staffsCount)
        ->and((int) DB::connection('lms')->table('roles')->count())->toBe($rolesCount)
        ->and((int) DB::connection('lms')->table('sm_human_departments')->count())->toBe($deptCount)
        ->and((int) DB::connection('lms')->table('sm_designations')->count())->toBe($desigCount);
});

/*
 * --- flat_percentage algorithm coverage (Task #5) ---------------------------
 *
 * The store path now accepts the new `flat_percentage` algorithm wired in
 * earlier tasks (model constant, strategy, registry binding). Validation is
 * dual-shape: callers may submit canonical basis points + centavos, OR
 * decimal-percent + decimal-pesos which prepareForValidation() converts into
 * basis points before strategy validation runs. Persisted JSON is always in
 * the canonical *_bp + centavos form so downstream computation stays
 * float-free.
 */

function validFlatPercentageBpPayload(string $effectiveFrom = '2026-01-01'): array
{
    return [
        'contribution_code' => StatutoryContribution::CODE_CITY_TAX,
        'label' => 'Quezon City local tax',
        'algorithm' => StatutoryContribution::ALGORITHM_FLAT_PERCENTAGE,
        'application_order' => 50,
        'applies_to' => StatutoryContribution::APPLIES_TO_GROSS_PAY,
        'effective_from' => $effectiveFrom,
        'rules' => [
            // Canonical bp + centavos shape — the same form the strategy
            // and the persisted JSON column carry.
            'rate_bp' => 150,            // 1.5%
            'employee_share_bp' => 10_000,
            'employer_share_bp' => 0,
            'cap' => null,
        ],
        'notes' => null,
    ];
}

it('persists a flat_percentage row with the canonical bp + centavos rules shape', function () {
    $user = storeAuthAs('super-admin');

    expect(StatutoryContribution::query()->count())->toBe(0);

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', validFlatPercentageBpPayload())
        ->assertRedirect('/admin/contribution-tables');

    $row = StatutoryContribution::query()->firstOrFail();

    expect($row->contribution_code)->toBe(StatutoryContribution::CODE_CITY_TAX)
        ->and($row->algorithm)->toBe(StatutoryContribution::ALGORITHM_FLAT_PERCENTAGE)
        ->and($row->application_order)->toBe(50)
        ->and($row->applies_to)->toBe(StatutoryContribution::APPLIES_TO_GROSS_PAY)
        ->and($row->effective_from->toDateString())->toBe('2026-01-01')
        ->and($row->effective_to)->toBeNull()
        ->and($row->rules)->toBe([
            'rate_bp' => 150,
            'employee_share_bp' => 10_000,
            'employer_share_bp' => 0,
            'cap' => null,
        ]);
});

it('normalises a decimal-percent flat_percentage payload into the canonical bp shape', function () {
    $user = storeAuthAs('super-admin');

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', [
            'contribution_code' => StatutoryContribution::CODE_CITY_TAX,
            'label' => 'Quezon City local tax',
            'algorithm' => StatutoryContribution::ALGORITHM_FLAT_PERCENTAGE,
            // application_order + applies_to deliberately omitted to exercise
            // prepareForValidation()'s defaults (100, gross_pay).
            'effective_from' => '2026-01-01',
            'rules' => [
                'rate_percent' => '1.5',
                'employee_share_percent' => '50',
                'employer_share_percent' => '50',
                'cap_amount' => '50000.00',
            ],
            'notes' => null,
        ])
        ->assertRedirect('/admin/contribution-tables');

    $row = StatutoryContribution::query()->firstOrFail();

    // Persisted JSON carries integer bp + centavos, regardless of input shape.
    expect($row->rules)->toBe([
        'rate_bp' => 150,
        'employee_share_bp' => 5_000,
        'employer_share_bp' => 5_000,
        'cap' => 5_000_000,
    ])
        ->and($row->application_order)->toBe(100)
        ->and($row->applies_to)->toBe(StatutoryContribution::APPLIES_TO_GROSS_PAY);
});

it('round-trips through the resolver: a stored flat_percentage row computes the configured rate', function () {
    $user = storeAuthAs('super-admin');

    // Store a 1.5% city tax, 100% employee, no cap.
    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', validFlatPercentageBpPayload())
        ->assertRedirect('/admin/contribution-tables');

    /** @var StatutoryContributionResolver $resolver */
    $resolver = app(StatutoryContributionResolver::class);

    // Input ₱100,000.00 (100_000_00 centavos).
    // total = 10_000_000 × 150 / 10_000 = 150_000 centavos (₱1,500.00)
    // employee = 150_000 × 10_000 / 10_000 = 150_000
    // employer = 150_000 - 150_000 = 0
    $result = $resolver->compute(
        StatutoryContribution::CODE_CITY_TAX,
        Money::fromCentavos(100_000_00),
        CarbonImmutable::parse('2026-06-01'),
    );

    expect($result->employeeShare->centavos())->toBe(150_000)
        ->and($result->employerShare->centavos())->toBe(0)
        ->and($result->employerEcShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(100_000_00);
});

it('rejects a flat_percentage payload whose share split does not sum to 10000 bp', function () {
    $user = storeAuthAs('super-admin');

    $payload = validFlatPercentageBpPayload();
    $payload['rules']['employee_share_bp'] = 6_000;
    $payload['rules']['employer_share_bp'] = 6_000;

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', $payload)
        ->assertSessionHasErrors('rules');

    expect(StatutoryContribution::query()->count())->toBe(0);
});

it('rejects a flat_percentage payload with rate_bp above 10000', function () {
    $user = storeAuthAs('super-admin');

    $payload = validFlatPercentageBpPayload();
    $payload['rules']['rate_bp'] = 20_000;

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', $payload)
        ->assertSessionHasErrors('rules');

    expect(StatutoryContribution::query()->count())->toBe(0);
});

it('rejects a flat_percentage payload with an unknown applies_to value', function () {
    $user = storeAuthAs('super-admin');

    $payload = validFlatPercentageBpPayload();
    $payload['applies_to'] = 'something_else';

    $this->actingAs($user)
        ->from('/admin/contribution-tables/create')
        ->post('/admin/contribution-tables', $payload)
        ->assertSessionHasErrors('applies_to');

    expect(StatutoryContribution::query()->count())->toBe(0);
});

it('rejects a flat_percentage store from an unauthenticated user', function () {
    $this->post('/admin/contribution-tables', validFlatPercentageBpPayload())
        ->assertRedirect('/login');

    expect(StatutoryContribution::query()->count())->toBe(0);
});
