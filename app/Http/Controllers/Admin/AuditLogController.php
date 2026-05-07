<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exports\AuditLogExport;
use App\Http\Controllers\Controller;
use App\Models\Pas\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 4 W14 — Audit log viewer.
 *
 * Read-only surface over `pas_audit_logs`. Filters: date range, actor,
 * action, auditable_type. Pagination at 25/page. Stage B sibling endpoint
 * streams the same query as CSV.
 *
 * Auth: super-admin + auditor. Auditor is the primary user — they review
 * the immutable trail. Super-admin gets the same view for diagnosis.
 * Other roles are denied; their day-to-day work has no need for the
 * audit trail.
 */
final class AuditLogController extends Controller
{
    /** @var list<string> */
    private const AUDIT_ROLES = ['super-admin', 'auditor'];

    public function index(Request $request): Response
    {
        $this->authorizeAudit();

        $filters = $this->resolveFilters($request);
        $entries = $this->buildQuery($filters)->paginate(25)->withQueryString();

        // Resolve actor names for the visible page only — single batched
        // lookup avoids N+1.
        $actorIds = collect($entries->items())
            ->pluck('actor_id')
            ->filter()
            ->unique();
        $actorNames = User::query()
            ->whereIn('id', $actorIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        return Inertia::render('admin/audit-logs/index', [
            'filters' => $filters,
            'entries' => [
                'data' => collect($entries->items())->map(
                    fn (AuditLog $log): array => self::serialiseEntry($log, $actorNames),
                ),
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
            'distinctActions' => $this->distinctActions(),
            'distinctAuditableTypes' => $this->distinctAuditableTypes(),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorizeAudit();

        $filters = $this->resolveFilters($request);
        $entries = $this->buildQuery($filters)->limit(10_000)->get();

        $filename = sprintf(
            'audit-log_%s_%s.csv',
            $filters['from'] ?? 'all',
            $filters['to'] ?? CarbonImmutable::now()->toDateString(),
        );

        $actorIds = $entries->pluck('actor_id')->filter()->unique();
        $actorNames = User::query()
            ->whereIn('id', $actorIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        return Excel::download(
            new AuditLogExport($entries, $actorNames),
            $filename,
            \Maatwebsite\Excel\Excel::CSV,
        );
    }

    private function authorizeAudit(): void
    {
        if (! auth()->user()?->hasAnyRole(self::AUDIT_ROLES)) {
            abort(403);
        }
    }

    /**
     * @return array{from: string|null, to: string|null, actor_id: int|null, action: string|null, auditable_type: string|null}
     */
    private function resolveFilters(Request $request): array
    {
        return [
            'from' => $request->filled('from')
                ? CarbonImmutable::parse((string) $request->input('from'))->toDateString()
                : null,
            'to' => $request->filled('to')
                ? CarbonImmutable::parse((string) $request->input('to'))->toDateString()
                : null,
            'actor_id' => $request->filled('actor_id') ? (int) $request->input('actor_id') : null,
            'action' => $request->filled('action') ? (string) $request->input('action') : null,
            'auditable_type' => $request->filled('auditable_type')
                ? (string) $request->input('auditable_type')
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildQuery(array $filters): Builder
    {
        $query = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($filters['from']) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if ($filters['to']) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }
        if ($filters['actor_id']) {
            $query->where('actor_id', $filters['actor_id']);
        }
        if ($filters['action']) {
            $query->where('action', $filters['action']);
        }
        if ($filters['auditable_type']) {
            $query->where('auditable_type', $filters['auditable_type']);
        }

        return $query;
    }

    /**
     * @param  Collection<int, User>  $actorNames
     * @return array<string, mixed>
     */
    public static function serialiseEntry(AuditLog $log, $actorNames): array
    {
        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'action' => $log->action,
            'auditable_type' => $log->auditable_type,
            'auditable_short_type' => self::shortType($log->auditable_type),
            'auditable_id' => $log->auditable_id,
            'actor_id' => $log->actor_id,
            'actor_name' => $log->actor_id ? $actorNames->get($log->actor_id)?->name : null,
            'before' => $log->before,
            'after' => $log->after,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
        ];
    }

    private static function shortType(?string $fqcn): ?string
    {
        if ($fqcn === null) {
            return null;
        }
        $parts = explode('\\', $fqcn);

        return end($parts) ?: $fqcn;
    }

    /**
     * @return list<string>
     */
    private function distinctActions(): array
    {
        return AuditLog::query()
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function distinctAuditableTypes(): array
    {
        return AuditLog::query()
            ->distinct()
            ->orderBy('auditable_type')
            ->pluck('auditable_type')
            ->filter()
            ->map(fn (string $fqcn): array => [
                'value' => $fqcn,
                'label' => self::shortType($fqcn) ?? $fqcn,
            ])
            ->values()
            ->all();
    }
}
