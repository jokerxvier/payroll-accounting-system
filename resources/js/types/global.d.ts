// Augmenting a module requires importing it: without this side-effect import
// TypeScript reads the `declare module` block below as declaring a *new*
// ambient module rather than merging into the real one, and every
// `usePage().props` in the app falls back to the package's empty
// `InertiaConfig` — i.e. `unknown`. Matches the example in
// `@inertiajs/core`'s own `InertiaConfig` docblock.
import '@inertiajs/core';
import type { Auth } from '@/types/auth';

export interface CurrentTenant {
    id: number;
    name: string;
    slug: string;
    /**
     * Null until a logo is uploaded — and also null if `storage:link` has
     * never been run, so the fallback mark has to be a real UI state rather
     * than a defensive afterthought.
     */
    logo_url: string | null;
}

export interface AvailableTenant {
    id: number;
    name: string;
    slug: string;
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            currentTenant: CurrentTenant | null;
            availableTenants: AvailableTenant[];
            tenantOverrideActive: boolean;
            sidebarOpen: boolean;
            sidebarHiddenSections: string[];
            [key: string]: unknown;
        };
    }
}
