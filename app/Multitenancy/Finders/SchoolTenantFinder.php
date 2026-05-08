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
 *      wins when present, regardless of the multi-tenant flag, because the
 *      operator explicitly chose this tenant context.
 *   1. Subdomain / domain match against `pas_schools.domain`
 *   2. Path prefix `/schools/{slug}/...` (local dev / single-domain ops)
 *   3. Header `X-School-Slug: {slug}` (testing / future API)
 *
 * When `PAYROLL_MULTI_TENANT=false` and no override is set, every request
 * resolves to the seeded `slug=default` school so the rest of the
 * multitenancy pipeline (SwitchLmsConnection, future BelongsToTenant scope)
 * runs uniformly without changing observable behavior.
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

        if (! config('multitenancy.payroll_multi_tenant_enabled', false)) {
            // Single-tenant fallback — every request resolves to the default
            // school so the rest of the pipeline exercises real code paths
            // even before the multi-tenant flag is flipped.
            return School::query()
                ->where('slug', 'default')
                ->where('is_active', true)
                ->first();
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
    private function resolveSessionOverride(Request $request): ?School
    {
        if (! $request->hasSession()) {
            return null;
        }

        $overrideId = $request->session()->get('current_school_id_override');
        if (! is_int($overrideId) && ! (is_string($overrideId) && ctype_digit($overrideId))) {
            return null;
        }

        $user = $request->user();
        if ($user === null || ! method_exists($user, 'hasRole') || ! $user->hasRole('super-admin')) {
            return null;
        }

        return School::query()
            ->whereKey((int) $overrideId)
            ->where('is_active', true)
            ->first();
    }
}
