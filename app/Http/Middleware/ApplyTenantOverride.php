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
        if (! $request->hasSession()) {
            return $next($request);
        }

        $overrideId = $request->session()->get('current_school_id_override');
        if (! is_int($overrideId) && ! (is_string($overrideId) && ctype_digit($overrideId))) {
            return $next($request);
        }

        $user = $request->user();
        if ($user === null || ! method_exists($user, 'hasRole') || ! $user->hasRole('super-admin')) {
            return $next($request);
        }

        $school = School::query()
            ->whereKey((int) $overrideId)
            ->where('is_active', true)
            ->first();
        if ($school === null) {
            return $next($request);
        }

        $current = Tenant::current();
        if ($current && $current->getKey() === $school->getKey()) {
            return $next($request);
        }

        if ($current) {
            Tenant::forgetCurrent();
        }
        $school->makeCurrent();

        return $next($request);
    }
}
