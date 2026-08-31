<?php

namespace App\Http\Middleware;

use App\Models\Pas\School;
use App\Services\SchoolLogo;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Multitenancy\Models\Tenant;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = Tenant::current();

        // Cross-tenant powers (the school switcher dropdown + the override
        // banner) are gated by BOTH a NULL lms_user_id (the user is payroll-
        // native, not LMS-derived) AND the 'platform-admin' Spatie role.
        // Mirrors the gate in ApplyTenantOverride and SchoolPolicy::before().
        // LMS-derived users — including school-scoped super-admin — fall
        // through to availableTenants: [] and tenantOverrideActive: false,
        // and the React sidebar hides the switcher when availableTenants is
        // empty.
        $isPlatformAdmin = $user !== null
            && $user->getAttribute('lms_user_id') === null
            && method_exists($user, 'hasRole')
            && $user->hasRole('platform-admin');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                // Merge Spatie role names onto the standard auth.user payload so
                // the React side can role-gate UI affordances (e.g. the Admin
                // sidebar group). The user model is sent as-is otherwise; only
                // adding `roles` keeps every existing page unchanged.
                'user' => $user === null ? null : array_merge(
                    $user->toArray(),
                    ['roles' => $user->getRoleNames()->all()],
                ),
            ],
            // Surface the active tenant so the React sidebar can render a
            // context badge — operators always know which school's data they're
            // looking at. Sourced from Spatie's current-tenant facade so the
            // badge reflects whichever resolution strategy fired (subdomain /
            // path / header / single-tenant fallback / platform-admin override).
            'currentTenant' => $tenant instanceof School ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                // Null until a logo is uploaded, and null too if
                // `storage:link` has never been run — the sidebar falls back
                // to the generic mark either way.
                'logo_url' => app(SchoolLogo::class)->url($tenant),
            ] : null,
            // Only platform-admins (payroll-native + role) get the switcher
            // dropdown — feeds the in-app tenant switcher with the list of
            // active schools they can pin their session to. Empty array for
            // everyone else (including LMS-derived super-admin); the React
            // side hides the affordance when this is empty.
            'availableTenants' => $isPlatformAdmin
                ? School::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug'])
                    ->toArray()
                : [],
            'tenantOverrideActive' => $isPlatformAdmin
                && $request->hasSession()
                && $request->session()->has('current_school_id_override'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Presentational hide for sidebar nav groups (e.g. demos). Sourced
            // from config so it's flippable via PAYROLL_SIDEBAR_HIDDEN_SECTIONS
            // in .env without redeploying. Backend authorisation is unchanged;
            // this only controls whether the React sidebar renders the group.
            'sidebarHiddenSections' => config('payroll.sidebar_hidden_sections', []),
        ];
    }
}
