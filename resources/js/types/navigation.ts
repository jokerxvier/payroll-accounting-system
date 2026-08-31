import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /**
     * Key for the config-driven hide in `PAYROLL_SIDEBAR_HIDDEN_SECTIONS`,
     * e.g. `accounting.journal`.
     *
     * Section-level hiding was enough while a section was all-or-nothing.
     * Presenting a subset of Accounting needs finer granularity — keep Chart
     * of accounts and Invoices, drop Journal and Bills — without splitting the
     * group into artificial sections that exist only to be hidden.
     *
     * Presentational only: backend authorisation is untouched and a direct
     * URL still resolves.
     */
    hideKey?: string;
    /**
     * Roles allowed to SEE this item, when they are narrower than the group's.
     *
     * Most items inherit the group gate — every Accounting screen answering to
     * `AccountingRoles::VIEW` needs nothing here. A few do not: entering
     * gateway credentials or posting the books open sit with far narrower
     * lists, and `CLAUDE.md` requires the visible-to-role set to EQUAL the
     * page's policy set rather than merely contain it, or the nav grows dead
     * links that 403 on click.
     */
    roles?: readonly string[];
};

/**
 * A labelled run of items inside one nav group.
 *
 * Accounting outgrew a flat list: eleven links under one heading gave no clue
 * which were day-to-day work and which were set up once. The subheadings are
 * the structure the module already had, made visible.
 */
export type NavSubGroup = {
    label: string;
    items: NavItem[];
};
