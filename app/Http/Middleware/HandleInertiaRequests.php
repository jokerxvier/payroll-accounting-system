<?php

namespace App\Http\Middleware;

use App\Models\Pas\School;
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
            // path / header / single-tenant fallback).
            'currentTenant' => $tenant instanceof School ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
