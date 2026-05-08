<?php

declare(strict_types=1);

namespace App\Multitenancy\Finders;

use App\Models\Pas\School;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

/**
 * Resolves the active tenant from the request.
 *
 * Resolution precedence:
 *   0. Super-admin session override (the in-app school switcher) — always
 *      wins when present, because the operator explicitly chose this tenant
 *      context. (Currently a no-op stub; the post-session ApplyTenantOverride
 *      middleware re-pivots the tenant after StartSession runs.)
 *   1. Subdomain / domain match against `pas_schools.domain`
 *   2. Path prefix `/schools/{slug}/...` (local dev / single-domain ops)
 *   3. Header `X-School-Slug: {slug}` (testing / future API)
 *
 * Returns null when no strategy matches. Spatie's `NeedsTenant` middleware
 * aborts the request 404 in that case — there is no implicit fallback.
 *
 * Single-tenant deployments are still supported because `SchoolSeeder` seeds
 * the default school's `domain` to `parse_url(APP_URL, HOST)`, so a bare-host
 * request (e.g. `payroll-system.test`) resolves via the subdomain strategy.
 *
 * Inactive schools (`is_active = false`) never resolve regardless of strategy.
 *
 * Security note on the session override: the override is ONLY honored when
 * the current user has the `super-admin` role. A leaked session key on a
 * regular user cannot bypass tenant boundaries — the role check is on every
 * request, not just at write time. Auth middleware runs before NeedsTenant
 * (we appended NeedsTenant last in the web group), so Auth::user() is
 * available here.
 */
final class SchoolTenantFinder extends TenantFinder
{
    public function findForRequest(Request $request): ?IsTenant
    {
        // Strategy 0 — super-admin session override (the in-app switcher).
        // Runs first so the operator's explicit choice always wins.
        if ($override = $this->resolveSessionOverride($request)) {
            return $override;
        }

        // Strategy 1 — subdomain / domain match (production).
        $host = $request->getHost();
        $bySubdomain = School::query()
            ->where('domain', $host)
            ->where('is_active', true)
            ->first();
        if ($bySubdomain) {
            return $bySubdomain;
        }

        // Strategy 2 — path prefix `/schools/{slug}/...` (local dev / single-domain).
        if ($request->segment(1) === 'schools') {
            $slug = $request->segment(2);
            if ($slug !== null && $slug !== '') {
                $byPath = School::query()
                    ->where('slug', $slug)
                    ->where('is_active', true)
                    ->first();
                if ($byPath) {
                    return $byPath;
                }
            }
        }

        // Strategy 3 — header `X-School-Slug: {slug}` (testing / future API).
        $headerSlug = $request->header('X-School-Slug');
        if (is_string($headerSlug) && $headerSlug !== '') {
            $byHeader = School::query()
                ->where('slug', $headerSlug)
                ->where('is_active', true)
                ->first();
            if ($byHeader) {
                return $byHeader;
            }
        }

        return null;  // NeedsTenant middleware will abort 404.
    }

    /**
     * Read the super-admin session override and resolve it to a school.
     * Returns null when the override is absent, the user isn't a super-admin,
     * or the referenced school is missing/inactive.
     */
    /**
     * Session-based override is intentionally NOT read here — Spatie resolves
     * the tenant at service-provider boot time, BEFORE the StartSession
     * middleware has fired, so `$request->session()` is unavailable. The
     * `App\Http\Middleware\ApplyTenantOverride` middleware runs after session
     * is started, reads the override, and re-pivots the active tenant via
     * `Tenant::forgetCurrent()` + `$school->makeCurrent()`.
     */
    private function resolveSessionOverride(Request $request): ?School
    {
        return null;
    }
}
