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
 * When `PAYROLL_MULTI_TENANT=false` (default everywhere today), every
 * request resolves to the seeded `slug=default` school so the rest of
 * the multitenancy pipeline (SwitchLmsConnection, future BelongsToTenant
 * scope) runs uniformly without changing observable behavior.
 *
 * When the flag is true, three strategies in order:
 *   1. Subdomain / domain match against `pas_schools.domain`
 *   2. Path prefix `/schools/{slug}/...` (local dev / single-domain ops)
 *   3. Header `X-School-Slug: {slug}` (testing / future API)
 *
 * Inactive schools (`is_active = false`) never resolve regardless of strategy.
 */
final class SchoolTenantFinder extends TenantFinder
{
    public function findForRequest(Request $request): ?IsTenant
    {
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
}
