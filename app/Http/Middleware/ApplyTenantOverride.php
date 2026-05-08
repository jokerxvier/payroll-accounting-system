<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Pas\School;
use Closure;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-pivots the active tenant when a super-admin has pinned a school via the
 * in-app switcher.
 *
 * Why this is a separate middleware instead of part of the SchoolTenantFinder:
 * Spatie's MultitenancyServiceProvider calls `Multitenancy->start()` during
 * the boot phase — before any middleware runs. At that point the session
 * hasn't been started, `$request->session()` throws, and `Auth::user()` is
 * unavailable. So the boot-time finder runs first and resolves a tenant via
 * subdomain/path/header. THIS middleware then runs after StartSession and
 * after Auth, reads the session-stored override id, and rebinds the active
 * tenant if needed.
 *
 * Place this in the web middleware group after session/auth-resolving
 * middleware and before any controller code that reads tenant-scoped data.
 *
 * Security:
 *   - The override is only honored when the user has the `super-admin` role.
 *     A leaked or guessed cookie on a non-super-admin session is ignored.
 *   - The override is read from the session, which is signed/encrypted by
 *     Laravel's default cookie pipeline, not from a raw cookie.
 *   - Inactive schools are never resolved.
 */
final class ApplyTenantOverride
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $isSuperAdmin = $user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole('super-admin');

        // Strategy 1 — non-super-admin pinning (defense in depth).
        // Ordinary users (HR, payroll-officer, auditor, employee) are locked
        // to the school whose LMS authenticated them. The school id is
        // stamped onto pas_users on every successful login by
        // UpsertPasUserFromLms. Even if a malicious request crafts a path
        // prefix or X-School-Slug header to pivot the boot-time finder to
        // another school, this middleware overrides back to the user's
        // pinned school.
        if ($user !== null && ! $isSuperAdmin) {
            $userSchoolId = $user->getAttribute('school_id');
            if (is_int($userSchoolId) || (is_string($userSchoolId) && ctype_digit($userSchoolId))) {
                $this->pivotTo((int) $userSchoolId);
            }

            return $next($request);
        }

        // Strategy 2 — super-admin session override (the in-app switcher).
        // Only honored for super-admin; the role check happens here, not
        // just at write-time, so a leaked session override on a downgraded
        // user's session is ignored.
        if (! $isSuperAdmin) {
            return $next($request);
        }

        if (! $request->hasSession()) {
            return $next($request);
        }

        $overrideId = $request->session()->get('current_school_id_override');
        if (! is_int($overrideId) && ! (is_string($overrideId) && ctype_digit($overrideId))) {
            return $next($request);
        }

        $this->pivotTo((int) $overrideId);

        return $next($request);
    }

    /**
     * Re-pivot the active tenant to the given school id when it differs from
     * the currently-resolved tenant. Inactive schools and missing rows are
     * silently skipped (the existing tenant stays current), which is the
     * conservative choice for the non-super-admin path: we never accidentally
     * downgrade a logged-in user to a "no tenant" state mid-request.
     */
    private function pivotTo(int $schoolId): void
    {
        $school = School::query()
            ->whereKey($schoolId)
            ->where('is_active', true)
            ->first();
        if ($school === null) {
            return;
        }

        $current = Tenant::current();
        if ($current && $current->getKey() === $school->getKey()) {
            return;
        }

        if ($current) {
            Tenant::forgetCurrent();
        }
        $school->makeCurrent();
    }
}
