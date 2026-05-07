<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Pas\AuditLog;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollAdjustment;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\PayPeriodRepositoryInterface;
use App\Repositories\Contracts\PayrollRunRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Composes the read-only dashboard payload from four repositories.
 *
 * The dashboard is decision-support: it answers "what should I do next?".
 * KPIs, recent runs, and recent activity are role-scoped:
 *
 *   - super-admin / auditor: see everything.
 *   - payroll-officer:       payroll-shape audit rows + run-centric CTA.
 *   - hr:                    employee-shape audit rows + employee CTA.
 *   - employee:              empty audit panel + dashboard CTA placeholder.
 *
 * The service does NO writes. It is safe to call on every dashboard render.
 */
final class DashboardService
{
    /** Recent-runs panel cap. */
    private const RECENT_RUNS_LIMIT = 5;

    /** Recent-activity panel cap. */
    private const RECENT_ACTIVITY_LIMIT = 8;

    public function __construct(
        private readonly EmployeeRepositoryInterface $employees,
        private readonly PayrollRunRepositoryInterface $payrollRuns,
        private readonly PayPeriodRepositoryInterface $payPeriods,
        private readonly AuditLogRepositoryInterface $auditLogs,
    ) {}

    public function assemble(User $user): DashboardData
    {
        $role = $this->resolveRole($user);

        $latestRun = $this->payrollRuns->latestNonVoided();
        $recentRuns = $this->payrollRuns->recentNonVoided(self::RECENT_RUNS_LIMIT);
        $currentPeriod = $this->payPeriods->latestOpen();

        $auditTypes = $this->auditableTypesForRole($role);
        $auditEntries = $this->auditLogs->recentForAuditableTypes(
            $auditTypes,
            self::RECENT_ACTIVITY_LIMIT,
        );

        return new DashboardData(
            kpis: $this->buildKpis($currentPeriod, $latestRun),
            actions: $this->buildActions($role),
            recentRuns: $this->buildRecentRuns($recentRuns),
            recentActivity: $this->buildRecentActivity($auditEntries),
            reminders: [],
        );
    }

    /**
     * @return array{
     *     activeEmployees: array{count: int, deltaThisMonth: int},
     *     currentPeriod: array{id: int, label: string, startDate: string, endDate: string, status: string}|null,
     *     latestRun: array{id: int, periodLabel: string, status: string, netPayCentavos: int, computedAt: string|null}|null
     * }
     */
    private function buildKpis(?PayPeriod $period, ?PayrollRun $run): array
    {
        return [
            'activeEmployees' => [
                'count' => $this->employees->countActiveStaff(),
                'deltaThisMonth' => $this->employees->countStaffJoinedThisMonth(),
            ],
            'currentPeriod' => $period === null ? null : [
                'id' => (int) $period->id,
                'label' => $this->formatPeriodLabel($period),
                'startDate' => $period->start_date->toDateString(),
                'endDate' => $period->end_date->toDateString(),
                'status' => $period->status,
            ],
            'latestRun' => $run === null ? null : [
                'id' => (int) $run->id,
                'periodLabel' => $run->payPeriod !== null
                    ? $this->formatPeriodLabel($run->payPeriod)
                    : '(unknown period)',
                'status' => $run->status,
                'netPayCentavos' => (int) $run->total_net_pay_centavos,
                'computedAt' => $run->computed_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{role: string, primaryCta: array{label: string, href: string}, secondaryCtas: list<array{label: string, href: string}>}
     */
    private function buildActions(string $role): array
    {
        return DashboardRoleResolver::actionsForRole($role);
    }

    /**
     * @param  Collection<int, PayrollRun>  $runs
     * @return list<array{id: int, periodLabel: string, status: string, employeeCount: int, netTotalCentavos: int, computedAt: string|null}>
     */
    private function buildRecentRuns(Collection $runs): array
    {
        return array_values($runs
            ->map(fn (PayrollRun $run): array => [
                'id' => (int) $run->id,
                'periodLabel' => $run->payPeriod !== null
                    ? $this->formatPeriodLabel($run->payPeriod)
                    : '(unknown period)',
                'status' => $run->status,
                'employeeCount' => (int) $run->total_employees,
                'netTotalCentavos' => (int) $run->total_net_pay_centavos,
                'computedAt' => $run->computed_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @param  Collection<int, AuditLog>  $entries
     * @return list<array{id: int, actor: array{name: string, initials: string}, action: string, targetLabel: string, occurredAt: string}>
     */
    private function buildRecentActivity(Collection $entries): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        // Batch-resolve actor names. Mirrors AuditLogController to keep the
        // dashboard query budget tight (one query for N actors, not N).
        /** @var list<int> $actorIds */
        $actorIds = $entries
            ->pluck('actor_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var array<int, string> $namesById */
        $namesById = $actorIds === []
            ? []
            : User::query()
                ->whereIn('id', $actorIds)
                ->pluck('name', 'id')
                ->all();

        $rows = [];
        foreach ($entries as $entry) {
            $actorName = $entry->actor_id !== null
                ? ($namesById[$entry->actor_id] ?? 'Unknown user')
                : 'System';

            /** @var CarbonImmutable|null $createdAt */
            $createdAt = $entry->created_at;
            $occurredAt = $createdAt?->toIso8601String() ?? '';

            $rows[] = [
                'id' => (int) $entry->id,
                'actor' => [
                    'name' => $actorName,
                    'initials' => $this->initialsFor($actorName),
                ],
                'action' => (string) $entry->action,
                'targetLabel' => $this->targetLabelFor($entry),
                'occurredAt' => $occurredAt,
            ];
        }

        return $rows;
    }

    /**
     * Resolve the active payroll role for the user, defaulting to 'employee'
     * when the user has no payroll role assigned. Spatie's `getRoleNames()`
     * returns an Eloquent Collection; we take the first matching slug.
     */
    private function resolveRole(User $user): string
    {
        /** @var Collection<int, string> $roles */
        $roles = $user->getRoleNames();

        foreach (DashboardRoleResolver::PAYROLL_ROLES as $candidate) {
            if ($roles->contains($candidate)) {
                return $candidate;
            }
        }

        return 'employee';
    }

    /**
     * Returns the auditable_type FQCN allowlist for the given role.
     *
     *   - super-admin / auditor: null = no filter (all rows).
     *   - payroll-officer:       payroll-shape models.
     *   - hr:                    employee-shape models.
     *   - employee:              [] = match nothing (empty panel).
     *
     * @return list<class-string>|null
     */
    private function auditableTypesForRole(string $role): ?array
    {
        return match ($role) {
            'super-admin', 'auditor' => null,
            'payroll-officer' => [
                PayrollRun::class,
                Payslip::class,
                PayPeriod::class,
                PayrollAdjustment::class,
                EmployeeLoan::class,
                EmployeeDeduction::class,
                EmployeeAllowance::class,
            ],
            'hr' => [
                EmployeeProfile::class,
                EmployeeAllowance::class,
                EmployeeDeduction::class,
                EmployeeLoan::class,
            ],
            default => [],
        };
    }

    /**
     * "May 2026" — derived from the period's `start_date`. We prefer this
     * over `code` because codes drift in shape (e.g. "2026-05" vs "2026-3829")
     * across factories and test fixtures.
     */
    private function formatPeriodLabel(PayPeriod $period): string
    {
        return $period->start_date->format('F Y');
    }

    /**
     * Two-letter initials for an actor name, used by the avatar placeholder.
     * "Maria Santos" → "MS". "Bono"  → "BO". Empty → "??".
     */
    private function initialsFor(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '??';
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];

        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
        }

        return strtoupper(mb_substr($parts[0], 0, 2));
    }

    /**
     * "PayrollRun #42" — short class name + id. Mirrors AuditLogController's
     * shortType helper.
     */
    private function targetLabelFor(AuditLog $entry): string
    {
        $type = (string) $entry->auditable_type;
        $parts = explode('\\', $type);
        $last = (string) end($parts);
        $short = $last !== '' ? $last : $type;

        return sprintf('%s #%d', $short, (int) $entry->auditable_id);
    }
}
