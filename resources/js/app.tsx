import { createInertiaApp } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Payroll and Accounting';

// Every page, and nothing else.
//
// Written out rather than left to the Inertia Vite plugin, whose default glob
// is `./pages/**/*.tsx` and cannot be told to leave anything out. That glob
// also matches `__tests__/*.test.tsx`, so every page test — and a copy of
// Vitest — was built into production, and each test became a second importer
// of the page it tests. A page with two importers is folded into a shared
// chunk with no manifest entry of its own, and `@vite()` in app.blade.php
// then cannot find it: the invoice list returned a 500 from any fresh build.
const pages = import.meta.glob<{ default: ResolvedComponent }>([
    './pages/**/*.tsx',
    '!./pages/**/__tests__/**',
]);

createInertiaApp({
    resolve: async (name) => {
        const page = pages[`./pages/${name}.tsx`];

        if (page === undefined) {
            throw new Error(`Page not found: ${name}`);
        }

        return (await page()).default;
    },
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            // Customer-facing pages render their own chrome and must NOT get
            // AppLayout — it draws the admin sidebar and reads `auth.user`,
            // neither of which exists for a guest paying an invoice.
            case name.startsWith('public/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
