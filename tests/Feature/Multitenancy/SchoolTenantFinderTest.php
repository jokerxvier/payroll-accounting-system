<?php

declare(strict_types=1);

use App\Models\Pas\School;
use App\Multitenancy\Finders\SchoolTenantFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/*
 * Phase C.1 — SchoolTenantFinder.
 *
 * The finder is the single decision point for "which School row drives this
 * request's lms connection". Two top-level branches:
 *  - PAYROLL_MULTI_TENANT=false (default): always returns the seeded
 *    `slug=default` school so the rest of the pipeline runs uniformly
 *    without any observable behavior change.
 *  - PAYROLL_MULTI_TENANT=true: tries subdomain → path → header in order.
 *    Returns null when nothing matches; NeedsTenant middleware aborts.
 *
 * Inactive schools never resolve via any strategy.
 */

beforeEach(function (): void {
    // Default flag state — single-tenant. Individual tests flip to true.
    config(['multitenancy.payroll_multi_tenant_enabled' => false]);

    // The global Pest beforeEach seeds the default school for every test so
    // HTTP-driven suites pass through Spatie's NeedsTenant middleware without
    // a 503. The finder tests assert raw resolution semantics directly, so
    // clear that row first to avoid masking precondition checks.
    School::query()->where('slug', 'default')->delete();
});

/**
 * @param  array<string, string|int>  $server
 * @param  array<string, string>  $headers
 */
function makeRequest(string $uri = '/dashboard', array $server = [], array $headers = []): Request
{
    $request = Request::create($uri, 'GET', [], [], [], $server);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

function finder(): SchoolTenantFinder
{
    return app(SchoolTenantFinder::class);
}

it('returns the default school regardless of host when flag is false', function (): void {
    $default = School::factory()->create(['slug' => 'default', 'is_active' => true]);
    School::factory()->create(['slug' => 'acme', 'domain' => 'acme.payroll.test']);

    $request = makeRequest('/dashboard', ['HTTP_HOST' => 'random-host.example.com']);

    $resolved = finder()->findForRequest($request);

    expect($resolved)->toBeInstanceOf(School::class);
    /** @var School $resolved */
    expect($resolved->getKey())->toBe($default->getKey());
});

it('returns null in single-tenant mode when default school is missing', function (): void {
    // No default school seeded.
    $request = makeRequest('/dashboard');

    $resolved = finder()->findForRequest($request);

    expect($resolved)->toBeNull();
});

it('returns null in single-tenant mode when default school is inactive', function (): void {
    School::factory()->create(['slug' => 'default', 'is_active' => false]);

    $request = makeRequest('/dashboard');

    $resolved = finder()->findForRequest($request);

    expect($resolved)->toBeNull();
});

it('resolves by domain match when multi-tenant flag is true', function (): void {
    config(['multitenancy.payroll_multi_tenant_enabled' => true]);
    $school = School::factory()->create([
        'slug' => 'acme',
        'domain' => 'acme.payroll.test',
        'is_active' => true,
    ]);

    $request = makeRequest('/dashboard', ['HTTP_HOST' => 'acme.payroll.test']);

    $resolved = finder()->findForRequest($request);

    expect($resolved)->toBeInstanceOf(School::class);
    /** @var School $resolved */
    expect($resolved->getKey())->toBe($school->getKey());
});

it('resolves by path prefix /schools/{slug} when multi-tenant flag is true', function (): void {
    config(['multitenancy.payroll_multi_tenant_enabled' => true]);
    $school = School::factory()->create([
        'slug' => 'acme',
        'domain' => null,
        'is_active' => true,
    ]);

    $request = makeRequest('/schools/acme/dashboard');

    $resolved = finder()->findForRequest($request);

    expect($resolved)->toBeInstanceOf(School::class);
    /** @var School $resolved */
    expect($resolved->getKey())->toBe($school->getKey());
});

it('resolves by X-School-Slug header when multi-tenant flag is true', function (): void {
    config(['multitenancy.payroll_multi_tenant_enabled' => true]);
    $school = School::factory()->create([
        'slug' => 'acme',
        'domain' => null,
        'is_active' => true,
    ]);

    $request = makeRequest('/dashboard', [], ['X-School-Slug' => 'acme']);

    $resolved = finder()->findForRequest($request);

    expect($resolved)->toBeInstanceOf(School::class);
    /** @var School $resolved */
    expect($resolved->getKey())->toBe($school->getKey());
});

it('returns null when multi-tenant flag is true and no strategy matches', function (): void {
    config(['multitenancy.payroll_multi_tenant_enabled' => true]);
    School::factory()->create([
        'slug' => 'acme',
        'domain' => 'acme.payroll.test',
        'is_active' => true,
    ]);

    $request = makeRequest('/dashboard', ['HTTP_HOST' => 'unknown.payroll.test']);

    $resolved = finder()->findForRequest($request);

    expect($resolved)->toBeNull();
});

it('does not resolve an inactive school by domain', function (): void {
    config(['multitenancy.payroll_multi_tenant_enabled' => true]);
    School::factory()->create([
        'slug' => 'acme',
        'domain' => 'acme.payroll.test',
        'is_active' => false,
    ]);

    $request = makeRequest('/dashboard', ['HTTP_HOST' => 'acme.payroll.test']);

    expect(finder()->findForRequest($request))->toBeNull();
});

it('does not resolve an inactive school by path prefix', function (): void {
    config(['multitenancy.payroll_multi_tenant_enabled' => true]);
    School::factory()->create([
        'slug' => 'acme',
        'domain' => null,
        'is_active' => false,
    ]);

    $request = makeRequest('/schools/acme/dashboard');

    expect(finder()->findForRequest($request))->toBeNull();
});

it('does not resolve an inactive school by header', function (): void {
    config(['multitenancy.payroll_multi_tenant_enabled' => true]);
    School::factory()->create([
        'slug' => 'acme',
        'domain' => null,
        'is_active' => false,
    ]);

    $request = makeRequest('/dashboard', [], ['X-School-Slug' => 'acme']);

    expect(finder()->findForRequest($request))->toBeNull();
});

it('prefers domain match over path prefix when both could resolve', function (): void {
    config(['multitenancy.payroll_multi_tenant_enabled' => true]);
    $byDomain = School::factory()->create([
        'slug' => 'by-domain',
        'domain' => 'acme.payroll.test',
        'is_active' => true,
    ]);
    School::factory()->create([
        'slug' => 'acme',
        'domain' => null,
        'is_active' => true,
    ]);

    $request = makeRequest('/schools/acme/dashboard', ['HTTP_HOST' => 'acme.payroll.test']);

    $resolved = finder()->findForRequest($request);

    expect($resolved)->toBeInstanceOf(School::class);
    /** @var School $resolved */
    expect($resolved->getKey())->toBe($byDomain->getKey());
});
