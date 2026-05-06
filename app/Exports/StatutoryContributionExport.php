<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Pas\StatutoryContribution;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Snapshot of every contribution-table row (Phase 3 W12).
 *
 * The full algorithm-specific `rules` blob serialises into a single
 * `rules_json` cell. Admins use the dedicated `/admin/contribution-tables`
 * UI for authoring/editing — this export is for audit, archival, and
 * external tooling rather than round-trip import (the bracket / band
 * arrays for the four PH strategies don't survive a spreadsheet round-trip
 * cleanly).
 *
 * Voided rows are included with their `voided_at` populated, so the export
 * is a true historical snapshot of the rule set.
 */
final class StatutoryContributionExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'id',
            'contribution_code',
            'label',
            'algorithm',
            'applies_to',
            'application_order',
            'effective_from',
            'effective_to',
            'voided_at',
            'rules_json',
            'created_at',
            'updated_at',
        ];
    }

    public function collection()
    {
        return StatutoryContribution::query()
            ->orderBy('contribution_code')
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (StatutoryContribution $row): array => [
                'id' => $row->id,
                'contribution_code' => $row->contribution_code,
                'label' => $row->label,
                'algorithm' => $row->algorithm,
                'applies_to' => $row->applies_to,
                'application_order' => $row->application_order,
                'effective_from' => $row->effective_from?->toDateString(),
                'effective_to' => $row->effective_to?->toDateString(),
                'voided_at' => $row->voided_at?->toIso8601String(),
                'rules_json' => json_encode($row->rules, JSON_THROW_ON_ERROR),
                'created_at' => $row->created_at?->toIso8601String(),
                'updated_at' => $row->updated_at?->toIso8601String(),
            ]);
    }
}
