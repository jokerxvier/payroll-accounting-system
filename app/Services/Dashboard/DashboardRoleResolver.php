<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

/**
 * Maps a payroll role slug onto the dashboard's primary + secondary CTAs.
 *
 * The mapping is intentionally hard-coded here rather than config-driven.
 * The set of payroll roles is closed (PLAN.md §2) and the CTAs ship with
 * the page, not as runtime tunables.
 */
final class DashboardRoleResolver
{
    /**
     * The closed set of payroll roles, in resolution priority order. When
     * a user has multiple roles (e.g. `super-admin` + `payroll-officer`),
     * the first match wins. Super-admin first matches the convention used
     * by AuditLogController and ReportsController.
     *
     * @var list<string>
     */
    public const PAYROLL_ROLES = [
        'super-admin',
        'payroll-officer',
        'hr',
        'auditor',
        'employee',
    ];

    /**
     * @return array{role: string, primaryCta: array{label: string, href: string}, secondaryCtas: list<array{label: string, href: string}>}
     */
    public static function actionsForRole(string $role): array
    {
        return match ($role) {
            'super-admin', 'payroll-officer' => [
                'role' => $role,
                'primaryCta' => [
                    'label' => 'Start payroll run',
                    'href' => route('admin.payroll-runs.create'),
                ],
                'secondaryCtas' => [
                    ['label' => 'View employees', 'href' => route('employees.index')],
                    ['label' => 'View audit log', 'href' => route('admin.audit-logs.index')],
                ],
            ],
            'hr' => [
                'role' => $role,
                'primaryCta' => [
                    'label' => 'Manage employees',
                    'href' => route('employees.index'),
                ],
                'secondaryCtas' => [
                    ['label' => 'View payroll runs', 'href' => route('admin.payroll-runs.index')],
                ],
            ],
            'auditor' => [
                'role' => $role,
                'primaryCta' => [
                    'label' => 'View audit log',
                    'href' => route('admin.audit-logs.index'),
                ],
                'secondaryCtas' => [
                    ['label' => 'View payroll runs', 'href' => route('admin.payroll-runs.index')],
                ],
            ],
            default => [
                // 'employee' or any other slug: placeholder until employee
                // self-service ships. Single safe link back to dashboard.
                'role' => 'employee',
                'primaryCta' => [
                    'label' => 'View dashboard',
                    'href' => route('dashboard'),
                ],
                'secondaryCtas' => [],
            ],
        };
    }
}
