<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Pas\AuditLog;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Phase 4 W14 Stage B — CSV export of the filtered audit log. Used for
 * external review (auditor handoff, retention archive). The before / after
 * JSON blobs are inlined as compacted JSON strings so the row stays grep-able
 * without losing the diff payload.
 */
final class AuditLogExport implements FromCollection, WithHeadings
{
    /**
     * @param  Collection<int, AuditLog>  $entries
     * @param  Collection<int, mixed>  $actorNames  keyed by user id
     */
    public function __construct(
        private readonly Collection $entries,
        private readonly Collection $actorNames,
    ) {}

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID',
            'Timestamp',
            'Actor ID',
            'Actor Name',
            'Action',
            'Target Type',
            'Target ID',
            'Before (JSON)',
            'After (JSON)',
            'IP',
            'User Agent',
        ];
    }

    public function collection(): Collection
    {
        return $this->entries->map(fn (AuditLog $log): array => [
            $log->id,
            $log->created_at?->toIso8601String(),
            $log->actor_id,
            $log->actor_id ? $this->actorNames->get($log->actor_id)?->full_name : '',
            $log->action,
            self::shortType($log->auditable_type),
            $log->auditable_id,
            $log->before !== null ? json_encode($log->before, JSON_UNESCAPED_SLASHES) : '',
            $log->after !== null ? json_encode($log->after, JSON_UNESCAPED_SLASHES) : '',
            $log->ip,
            $log->user_agent,
        ]);
    }

    private static function shortType(?string $fqcn): string
    {
        if ($fqcn === null) {
            return '';
        }
        $parts = explode('\\', $fqcn);

        return end($parts) ?: $fqcn;
    }
}
