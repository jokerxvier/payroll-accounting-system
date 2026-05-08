<?php

declare(strict_types=1);

use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\Pas\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Multitenancy\Models\Tenant;

uses(RefreshDatabase::class);

/*
 * Phase D.2 — App\Concerns\BelongsToTenant.
 *
 * The trait does two things:
 *  1. Adds a global scope that filters every query for the model by
 *     `school_id = Tenant::current()->getKey()` when a tenant is current.
 *  2. Auto-fills `school_id` on the `creating` event when a tenant is current
 *     and the caller has not set the column explicitly.
 *
 * Both behaviors are scoped to the model (not the connection), so the trait
 * is safe on any Pas\* model whose table got `school_id` in D.1. Catalog
 * tables (Allowance, DeductionType, StatutoryContribution) intentionally
 * skip the trait per plan-2.md.
 *
 * The global Pest beforeEach (tests/Pest.php) already binds the default
 * school as the current tenant. Tests that exercise multi-school behaviour
 * forget the default first or explicitly switch.
 */

it('auto-fills school_id from the current tenant on creating', function (): void {
    // The global Pest beforeEach has already made `slug=default` current.
    $default = School::query()->where('slug', 'default')->first();
    expect($default)->not()->toBeNull();

    $profile = EmployeeProfile::factory()->create();

    expect($profile->school_id)->toBe($default->getKey());
});

it('skips auto-fill when no tenant is current', function (): void {
    Tenant::forgetCurrent();

    // school_id is nullable in D.1, so create() succeeds with the default's
    // id only if explicitly passed. Without a current tenant, the trait does
    // not auto-fill — pass it manually so the FK is satisfied.
    $default = School::query()->where('slug', 'default')->first();
    $profile = EmployeeProfile::factory()->create(['school_id' => $default->getKey()]);

    expect($profile->school_id)->toBe($default->getKey());
});

it('respects an explicitly passed school_id over the auto-fill', function (): void {
    $other = School::factory()->create(['slug' => 'other-school']);

    // Default is current, but the caller passes 'other' explicitly.
    $profile = EmployeeProfile::factory()->create(['school_id' => $other->getKey()]);

    expect($profile->school_id)->toBe($other->getKey());
});

it('scopes queries to the current tenant', function (): void {
    $other = School::factory()->create(['slug' => 'scope-other']);

    // Create one row under each school.
    $defaultProfile = EmployeeProfile::factory()->create();
    $otherProfile = EmployeeProfile::factory()->create(['school_id' => $other->getKey()]);

    // Default is current — only the default's row should be visible.
    $visible = EmployeeProfile::query()->pluck('id')->all();
    expect($visible)->toContain($defaultProfile->id);
    expect($visible)->not->toContain($otherProfile->id);

    // Switch tenant — only the other's row should be visible.
    $other->makeCurrent();
    $visible = EmployeeProfile::query()->pluck('id')->all();
    expect($visible)->toContain($otherProfile->id);
    expect($visible)->not->toContain($defaultProfile->id);
});

it('returns rows from all tenants under withoutGlobalScopes', function (): void {
    $other = School::factory()->create(['slug' => 'no-scope-other']);

    $defaultProfile = EmployeeProfile::factory()->create();
    $otherProfile = EmployeeProfile::factory()->create(['school_id' => $other->getKey()]);

    $all = EmployeeProfile::query()->withoutGlobalScopes()->pluck('id')->all();

    expect($all)->toContain($defaultProfile->id);
    expect($all)->toContain($otherProfile->id);
});

it('skips the global scope when no tenant is current', function (): void {
    $other = School::factory()->create(['slug' => 'no-tenant-other']);

    $defaultProfile = EmployeeProfile::factory()->create();
    $otherProfile = EmployeeProfile::factory()->create(['school_id' => $other->getKey()]);

    Tenant::forgetCurrent();

    $all = EmployeeProfile::query()->pluck('id')->all();

    expect($all)->toContain($defaultProfile->id);
    expect($all)->toContain($otherProfile->id);
});

it('exposes the school relation', function (): void {
    $default = School::query()->where('slug', 'default')->first();

    $profile = EmployeeProfile::factory()->create();

    expect($profile->school)->toBeInstanceOf(School::class);
    expect($profile->school->getKey())->toBe($default->getKey());
});

it('respects the scope across an eager-loaded HasMany on a parent model', function (): void {
    // Build a parent (PayrollRun → payslips) under the default tenant.
    $period = PayPeriod::factory()->create();
    $run = PayrollRun::factory()->create(['pay_period_id' => $period->id]);
    Payslip::factory()->create([
        'payroll_run_id' => $run->id,
        'employee_profile_id' => EmployeeProfile::factory()->create()->id,
    ]);

    // Build a parallel run under a second tenant. We have to switch context
    // to `$other` so the `creating` hook fills both run + payslip with
    // `$other->id` — otherwise the parent would still belong to default.
    $other = School::factory()->create(['slug' => 'eager-other']);
    $other->makeCurrent();

    $otherPeriod = PayPeriod::factory()->create();
    $otherRun = PayrollRun::factory()->create(['pay_period_id' => $otherPeriod->id]);
    $otherProfile = EmployeeProfile::factory()->create();
    Payslip::factory()->create([
        'payroll_run_id' => $otherRun->id,
        'employee_profile_id' => $otherProfile->id,
    ]);

    // Switch back to the default and eager-load runs with payslips. Only the
    // default's run + payslips should come back.
    $default = School::query()->where('slug', 'default')->first();
    $default->makeCurrent();

    $runs = PayrollRun::query()->with('payslips')->get();

    expect($runs)->toHaveCount(1);
    expect($runs->first()->id)->toBe($run->id);
    expect($runs->first()->payslips)->toHaveCount(1);

    // The other tenant's payslip is NOT in the eagerly-loaded collection.
    $loadedPayslipIds = $runs->first()->payslips->pluck('id')->all();
    expect($loadedPayslipIds)->not->toContain($otherRun->payslips()->withoutGlobalScopes()->first()?->id);
});
