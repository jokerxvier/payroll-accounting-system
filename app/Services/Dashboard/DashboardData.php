<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

/**
 * Typed payload for the `dashboard` Inertia page.
 *
 * Each field maps 1:1 to the React-side `DashboardPageProps` interface.
 * The shape is the contract handoff with the frontend; treat it as locked
 * and update both sides in lockstep.
 *
 * Money is passed as raw integer centavos (suffix `Centavos`) so the React
 * `<Money cents={...}>` component can render it without parsing — matching
 * the existing convention used elsewhere in this codebase (employee index,
 * payroll-run show, etc.).
 */
final readonly class DashboardData
{
    /**
     * @param  array{
     *     activeEmployees: array{count: int, deltaThisMonth: int},
     *     currentPeriod: array{id: int, label: string, startDate: string, endDate: string, status: string}|null,
     *     latestRun: array{id: int, periodLabel: string, status: string, netPayCentavos: int, computedAt: string|null}|null
     * }  $kpis
     * @param  array{
     *     role: string,
     *     primaryCta: array{label: string, href: string},
     *     secondaryCtas: list<array{label: string, href: string}>
     * }  $actions
     * @param  list<array{
     *     id: int,
     *     periodLabel: string,
     *     status: string,
     *     employeeCount: int,
     *     netTotalCentavos: int,
     *     computedAt: string|null
     * }>  $recentRuns
     * @param  list<array{
     *     id: int,
     *     actor: array{name: string, initials: string},
     *     action: string,
     *     targetLabel: string,
     *     occurredAt: string
     * }>  $recentActivity
     * @param  list<array{kind: string, message: string, href: string}>  $reminders
     */
    public function __construct(
        public array $kpis,
        public array $actions,
        public array $recentRuns,
        public array $recentActivity,
        public array $reminders,
    ) {}

    /**
     * @return array{
     *     kpis: array<string, mixed>,
     *     actions: array<string, mixed>,
     *     recentRuns: list<array<string, mixed>>,
     *     recentActivity: list<array<string, mixed>>,
     *     reminders: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'kpis' => $this->kpis,
            'actions' => $this->actions,
            'recentRuns' => $this->recentRuns,
            'recentActivity' => $this->recentActivity,
            'reminders' => $this->reminders,
        ];
    }
}
