import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    Calculator,
    CalendarDays,
    FileSearch,
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
import { index as adminSchoolsIndex } from '@/routes/admin/schools';
import { index as employeesIndex } from '@/routes/employees';
import { show as payrollPreviewShow } from '@/routes/payroll/preview';
import type { NavItem } from '@/types';

/**
 * Sidebar visibility constants — each one mirrors the role list in a
 * specific backend policy or controller gate. Keep these in sync with the
 * source of truth on every PR. Per CLAUDE.md "Sidebar gating must mirror
 * the page's authorization": the visible-to-role set must equal the
 * page's policy/gate set.
 */

// Mirrors EmployeeProfilePolicy::viewAny().
const EMPLOYEE_DIRECTORY_ROLES = [
    'super-admin',
    'payroll-officer',
    'hr',
    'auditor',
] as const;

// Mirrors PayrollPreviewPolicy::preview() (Gate) and PayrollRunPolicy::viewAny()
// + ReportsController::REPORT_ROLES. The "maker" half of the maker-checker
// payroll workflow.
const PAYROLL_MAKER_ROLES = ['super-admin', 'payroll-officer', 'hr'] as const;

// Mirrors viewAny() on AllowancePolicy, DeductionTypePolicy,
// StatutoryContributionPolicy, and PayPeriodPolicy. Catalog read-only for
// makers; catalog mutation is super-admin (gated server-side via `can`).
const CATALOG_READ_ROLES = ['super-admin', 'payroll-officer', 'hr'] as const;

// Mirrors AuditLogController::ALLOWED_ROLES.
const AUDIT_ROLES = ['super-admin', 'auditor'] as const;

// Mirrors app/Policies/Pas/SchoolPolicy.php — super-admin only.
const SCHOOLS_ADMIN_ROLES = ['super-admin'] as const;

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const employeeNavItems: NavItem[] = [
    {
        title: 'Employees',
        href: employeesIndex(),
        icon: Users,
    },
];

const payrollNavItems: NavItem[] = [
    {
        title: 'Preview',
        href: payrollPreviewShow(),
        icon: Calculator,
    },
    {
        title: 'Payroll runs',
        href: adminPayrollRunsIndex(),
        icon: PlayCircle,
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

const catalogNavItems: NavItem[] = [
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
];

const auditNavItems: NavItem[] = [
    {
        title: 'Audit log',
        href: '/admin/audit-logs',
        icon: FileSearch,
    },
];

const schoolsAdminNavItems: NavItem[] = [
    {
        title: 'Schools',
        href: adminSchoolsIndex(),
        icon: Building2,
    },
];

const footerNavItems: NavItem[] = [];

function hasAnyRole(
    userRoles: readonly string[],
    allowed: readonly string[],
): boolean {
    return allowed.some((role) => userRoles.includes(role));
}

export function AppSidebar() {
    const { auth, currentTenant } = usePage().props;
    const userRoles = auth.user?.roles ?? [];

    const canViewEmployees = hasAnyRole(userRoles, EMPLOYEE_DIRECTORY_ROLES);
    const canViewPayroll = hasAnyRole(userRoles, PAYROLL_MAKER_ROLES);
    const canViewCatalog = hasAnyRole(userRoles, CATALOG_READ_ROLES);
    const canViewAudit = hasAnyRole(userRoles, AUDIT_ROLES);
    const canManageSchools = hasAnyRole(userRoles, SCHOOLS_ADMIN_ROLES);

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
                {currentTenant ? (
                    <div
                        className="mx-2 mt-1 flex items-center gap-1.5 rounded-md border border-sidebar-border/60 bg-sidebar-accent/40 px-2 py-1 text-xs group-data-[collapsible=icon]:hidden"
                        title={`Active tenant: ${currentTenant.name} (${currentTenant.slug})`}
                    >
                        <Building2
                            className="h-3 w-3 flex-shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span className="truncate font-medium text-sidebar-foreground">
                            {currentTenant.name}
                        </span>
                    </div>
                ) : null}
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                {canViewEmployees && (
                    <SidebarSection
                        label="Directory"
                        items={employeeNavItems}
                    />
                )}
                {canViewPayroll && (
                    <SidebarSection label="Payroll" items={payrollNavItems} />
                )}
                {canViewCatalog && (
                    <SidebarSection label="Catalog" items={catalogNavItems} />
                )}
                {canViewAudit && (
                    <SidebarSection label="Audit" items={auditNavItems} />
                )}
                {canManageSchools && (
                    <SidebarSection
                        label="Tenants"
                        items={schoolsAdminNavItems}
                    />
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
