import type { Auth } from '@/types/auth';

export interface CurrentTenant {
    id: number;
    name: string;
    slug: string;
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
            [key: string]: unknown;
        };
    }
}
