import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    BookOpen,
    Calculator,
    CalendarDays,
    FolderGit2,
    LayoutGrid,
    MinusCircle,
    PlayCircle,
    PlusCircle,
    ShieldCheck,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { dashboard } from '@/routes';
import { index as adminAllowancesIndex } from '@/routes/admin/allowances';
import { index as adminContributionTablesIndex } from '@/routes/admin/contribution-tables';
import { index as adminDeductionTypesIndex } from '@/routes/admin/deduction-types';
import { index as adminPayPeriodsIndex } from '@/routes/admin/pay-periods';
import { index as adminPayrollRunsIndex } from '@/routes/admin/payroll-runs';
import { index as employeesIndex } from '@/routes/employees';
import { show as payrollPreviewShow } from '@/routes/payroll/preview';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Employees',
        href: employeesIndex(),
        icon: Users,
    },
];

/**
 * Payroll section. Mirrors the `payroll.preview` Gate envelope from
 * {@see \App\Policies\PayrollPreviewPolicy} — visible to super-admin,
 * payroll-officer, and hr roles. Auditor is intentionally excluded
 * (auditors review the immutable record, not speculative figures).
 */
const payrollNavItems: NavItem[] = [
    {
        title: 'Preview',
        href: payrollPreviewShow(),
        icon: Calculator,
    },
    {
        title: 'Payroll summary',
        href: '/admin/reports/payroll-summary',
        icon: BarChart3,
    },
    {
        title: 'Employee history',
        href: '/admin/reports/employee-history',
        icon: Users,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Contribution tables',
        href: adminContributionTablesIndex(),
        icon: ShieldCheck,
    },
    {
        title: 'Deduction types',
        href: adminDeductionTypesIndex(),
        icon: MinusCircle,
    },
    {
        title: 'Allowances',
        href: adminAllowancesIndex(),
        icon: PlusCircle,
    },
    {
        title: 'Pay periods',
        href: adminPayPeriodsIndex(),
        icon: CalendarDays,
    },
    {
        title: 'Payroll runs',
        href: adminPayrollRunsIndex(),
        icon: PlayCircle,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

/**
 * Roles allowed to see the Payroll preview entry. Mirrors
 * {@see \App\Policies\PayrollPreviewPolicy::preview()}. Keep these in sync —
 * the gate is the source of truth, this list is purely a UI affordance to
 * avoid surfacing a link that would 403 on click.
 */
const PAYROLL_PREVIEW_ROLES = ['super-admin', 'payroll-officer', 'hr'] as const;

export function AppSidebar() {
    const { auth } = usePage().props;
    const userRoles = auth.user?.roles ?? [];
    const isSuperAdmin = userRoles.includes('super-admin');
    const canPreviewPayroll = PAYROLL_PREVIEW_ROLES.some((role) =>
        userRoles.includes(role),
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                {canPreviewPayroll && (
                    <SidebarSection label="Payroll" items={payrollNavItems} />
                )}
                {isSuperAdmin && (
                    <SidebarSection label="Admin" items={adminNavItems} />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

function SidebarSection({ label, items }: { label: string; items: NavItem[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
