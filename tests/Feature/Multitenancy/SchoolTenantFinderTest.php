<?php

declare(strict_types=1);

use App\Models\Pas\School;
use App\Multitenancy\Finders\SchoolTenantFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/*
 * Phase C.1 / D.4 — SchoolTenantFinder.
 *
 * The finder is the single decision point for "which School row drives this
 * request's lms connection". Resolution order:
 *   1. Subdomain / domain match against pas_schools.domain
 *   2. Path prefix /schools/{slug}/...
 *   3. Header X-School-Slug: {slug}
 *   4. null (NeedsTenant middleware aborts the request 404)
 *
 * Phase D.4 removed the single-tenant fallback branch (return the seeded
 * default school when PAYROLL_MULTI_TENANT=false). Single-tenant deployments
 * still work because the SchoolSeeder seeds the default school's `domain` to
 * the APP_URL host, so a bare-host request resolves via strategy 1.
 *
 * Inactive schools never resolve via any strategy.
 */

beforeEach(function (): void {
    // The global Pest beforeEach seeds the default school for every test so
    // HTTP-driven suites pass through Spatie's NeedsTenant middleware. The
    // finder tests assert raw resolution semantics directly, so clear that
    // row first to avoid masking precondition checks (and so each test
    // controls exactly which schools are present).
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

it('resolves by domain match', function (): void {
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

it('resolves by path prefix /schools/{slug}', function (): void {
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

it('resolves by X-School-Slug header', function (): void {
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

it('returns null when no strategy matches (post-D.4 strict mode)', function (): void {
    School::factory()->create([
        'slug' => 'acme',
        'domain' => 'acme.payroll.test',
        'is_active' => true,
    ]);

    $request = makeRequest('/dashboard', ['HTTP_HOST' => 'unknown.payroll.test']);

    $resolved = finder()->findForRequest($request);

    expect($resolved)->toBeNull();
});

it('returns null when host has no matching school even if a default school exists', function (): void {
    // Post-D.4 the seeded `default` school is no longer treated as an
    // implicit fallback — only its `domain` column matters.
    School::factory()->create([
        'slug' => 'default',
        'domain' => 'payroll-system.test',
        'is_active' => true,
    ]);

    $request = makeRequest('/dashboard', ['HTTP_HOST' => 'random-host.example.com']);

    expect(finder()->findForRequest($request))->toBeNull();
});

it('does not resolve an inactive school by domain', function (): void {
    School::factory()->create([
        'slug' => 'acme',
        'domain' => 'acme.payroll.test',
        'is_active' => false,
    ]);

    $request = makeRequest('/dashboard', ['HTTP_HOST' => 'acme.payroll.test']);

    expect(finder()->findForRequest($request))->toBeNull();
});

it('does not resolve an inactive school by path prefix', function (): void {
    School::factory()->create([
        'slug' => 'acme',
        'domain' => null,
        'is_active' => false,
    ]);

    $request = makeRequest('/schools/acme/dashboard');

    expect(finder()->findForRequest($request))->toBeNull();
});

it('does not resolve an inactive school by header', function (): void {
    School::factory()->create([
        'slug' => 'acme',
        'domain' => null,
        'is_active' => false,
    ]);

    $request = makeRequest('/dashboard', [], ['X-School-Slug' => 'acme']);

    expect(finder()->findForRequest($request))->toBeNull();
});

it('prefers domain match over path prefix when both could resolve', function (): void {
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
