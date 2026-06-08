<?php

declare(strict_types=1);

use App\Models\Pas\School;
use App\Models\User;
use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Models\Tenant;

uses(RefreshDatabase::class);

/*
 * Platform admin role separation — cross-tenant vs LMS-pinned.
 *
 * The "platform admin" is a payroll-native user (no LMS counterpart) who
 * holds the multi-school deployment's cross-tenant powers:
 *  - listed in availableTenants → switcher dropdown visible
 *  - allowed by SchoolPolicy → /admin/schools registry accessible
 *  - allowed to POST /admin/schools/switch/{school} → session override
 *
 * LMS-derived users — including school-scoped super-admin — do NOT get
 * any of those affordances. They are pinned to their `school_id` by
 * ApplyTenantOverride regardless of any X-School-Slug header injection or
 * crafted path prefix.
 *
 * The discriminator is a NULL `pas_users.lms_user_id` column. Every gate
 * (ApplyTenantOverride, HandleInertiaRequests, SchoolPolicy::before)
 * checks BOTH the role AND the lms_user_id together so the three layers
 * agree on "what is a platform admin".
 */

beforeEach(function (): void {
    // The auth login test in this file exercises the new Fortify
    // payroll-native fallback path, which queries pas_users directly. The
    // fallback only fires when the LMS lookup returns null (no LMS row for
    // that email) — so we point the lms connection at the same in-memory
    // sqlite as the default connection (no shared `users` table data) so
    // the LMS lookup is well-formed and returns null cleanly.
    useLmsSqliteMirror();
});

/**
 * Build a payroll-native platform admin user. Mirrors the seeder payload —
 * NULL lms_user_id + platform-admin role.
 */
function platformAdminTestUser(): User
{
    $user = User::factory()->withoutLmsMirror()->create([
        'email' => 'admin@payroll.test',
        'name' => 'Platform Admin',
    ]);
    $user->syncRoles(['platform-admin']);

    return $user->fresh() ?? $user;
}

/**
 * Build an LMS-derived super-admin pinned to the given school. Mirrors the
 * production login flow: lms_user_id NOT NULL, school_id stamped from the
 * resolved tenant.
 */
function lmsDerivedSuperAdmin(School $school): User
{
    $user = User::factory()->create();
    DB::table('pas_users')
        ->where('id', $user->id)
        ->update(['school_id' => $school->getKey()]);
    $user->setAttribute('school_id', $school->getKey());
    $user->syncRoles(['super-admin']);

    return $user->fresh() ?? $user;
}

it('platform admin can log in via the payroll-native fallback', function (): void {
    // Forget the global Pest beforeEach's current tenant — the platform
    // admin login does not need to resolve a school first; the fallback
    // path verifies pas_users.password directly without any LMS hop.
    $this->seed(PlatformAdminSeeder::class);

    $response = $this->post(route('login.store'), [
        'email' => 'admin@payroll.test',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'admin@payroll.test')->first();
    expect($user)->not->toBeNull();
    expect($user->lms_user_id)->toBeNull();
    expect($user->school_id)->toBeNull();
    expect($user->getRoleNames()->all())->toBe(['platform-admin']);
});

it('platform admin sees availableTenants populated with all active schools', function (): void {
    $user = platformAdminTestUser();
    School::factory()->create(['slug' => 'school-a', 'name' => 'School A', 'is_active' => true]);
    School::factory()->create(['slug' => 'school-b', 'name' => 'School B', 'is_active' => true]);
    School::factory()->create(['slug' => 'inactive', 'name' => 'Inactive School', 'is_active' => false]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(function ($page): void {
        $page->where('availableTenants', function ($tenants) {
            $slugs = collect($tenants)->pluck('slug')->all();

            // Default is seeded by the global Pest beforeEach. Inactive
            // schools must NOT appear.
            expect($slugs)->toContain('default');
            expect($slugs)->toContain('school-a');
            expect($slugs)->toContain('school-b');
            expect($slugs)->not->toContain('inactive');

            return true;
        });
    });
});

it('LMS-derived super-admin sees empty availableTenants', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $user = lmsDerivedSuperAdmin($default);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(function ($page): void {
        $page->where('availableTenants', [])
            ->where('tenantOverrideActive', false);
    });
});

it('platform admin can POST /admin/schools/switch/{school} and the tenant pivots', function (): void {
    $user = platformAdminTestUser();
    $target = School::factory()->create(['slug' => 'pivot-target', 'is_active' => true]);

    // Pivot session.
    $response = $this->actingAs($user)
        ->post("/admin/schools/switch/{$target->id}");
    $response->assertRedirect();

    // Subsequent request should resolve the chosen school as the current
    // tenant. The override is applied by ApplyTenantOverride after the
    // boot-time finder sets the default; the override pivots to `target`.
    $next = $this->actingAs($user)->get('/dashboard');
    $next->assertOk();
    $next->assertInertia(function ($page) use ($target): void {
        $page->where('currentTenant.id', $target->id)
            ->where('currentTenant.slug', 'pivot-target')
            ->where('tenantOverrideActive', true);
    });
});

it('LMS-derived super-admin gets 403 on /admin/schools/switch/{school}', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $user = lmsDerivedSuperAdmin($default);
    $target = School::factory()->create(['slug' => 'forbidden-target', 'is_active' => true]);

    $response = $this->actingAs($user)
        ->post("/admin/schools/switch/{$target->id}");

    $response->assertForbidden();
});

it('LMS-derived user is pinned regardless of header injection', function (): void {
    // Two schools — user is pinned to the default; the header tries to
    // pivot to `other`. ApplyTenantOverride must override back.
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $other = School::factory()->create(['slug' => 'header-injected', 'is_active' => true]);

    $user = lmsDerivedSuperAdmin($default);

    // Multi-tenant flag enables header-strategy resolution at the boot
    // finder; ApplyTenantOverride then re-pins back to the user's school.
    config(['multitenancy.payroll_multi_tenant_enabled' => true]);

    $response = $this->actingAs($user)
        ->withHeaders(['X-School-Slug' => 'header-injected'])
        ->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(function ($page) use ($default): void {
        $page->where('currentTenant.id', $default->id)
            ->where('currentTenant.slug', 'default');
    });
});

it('PlatformAdminSeeder is idempotent', function (): void {
    $this->seed(PlatformAdminSeeder::class);
    $this->seed(PlatformAdminSeeder::class);
    $this->seed(PlatformAdminSeeder::class);

    $rows = DB::table('pas_users')
        ->where('email', 'admin@payroll.test')
        ->get();

    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row->lms_user_id)->toBeNull();
    expect($row->school_id)->toBeNull();
    expect($row->name)->toBe('Platform Admin');

    // The platform-admin role is exclusive — repeated seeding doesn't
    // accumulate other roles.
    $user = User::query()->where('email', 'admin@payroll.test')->first();
    expect($user)->not->toBeNull();
    expect($user->getRoleNames()->all())->toBe(['platform-admin']);
});

it('platform-admin role is denied switcher when assigned to an LMS-derived user', function (): void {
    // Defense in depth: lms_user_id IS NULL is the load-bearing gate, not
    // the role alone. An LMS-derived user that gets the platform-admin
    // role assigned (operator error) should NOT see availableTenants.
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $user = lmsDerivedSuperAdmin($default);
    $user->syncRoles(['platform-admin']);

    expect($user->fresh()->lms_user_id)->not->toBeNull();

    $response = $this->actingAs($user->fresh())->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(function ($page): void {
        $page->where('availableTenants', [])
            ->where('tenantOverrideActive', false);
    });
});
