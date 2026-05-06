<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Pas\AuditLog;
use Illuminate\Support\Collection;

/**
 * Read-side repository for `pas_audit_logs` consumed by the dashboard.
 *
 * The dashboard's "recent activity" panel is role-scoped: payroll-officers
 * see payroll-shape rows, hr sees employee-shape rows, auditors and super-
 * admins see everything. The role -> auditable_type mapping is owned by the
 * service layer; this repository accepts an explicit allowlist (or null
 * meaning "no filter").
 */
interface AuditLogRepositoryInterface
{
    /**
     * The N most recent audit log entries optionally filtered by
     * `auditable_type` against the supplied list of FQCNs.
     *
     * @param  list<class-string>|null  $types  null = no type filter ("all"),
     *                                          [] = match nothing, list = whitelist
     * @return Collection<int, AuditLog>
     */
    public function recentForAuditableTypes(?array $types, int $limit): Collection;
}
