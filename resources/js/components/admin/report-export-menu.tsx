import { Download, FileSpreadsheet, FileText, Table2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * Export menu shared by the reports pages.
 *
 * The Phase 4 acceptance criterion requires every report to export as
 * Excel, CSV, and PDF. Each is the same endpoint with a `format` query
 * parameter, so this component only has to append one.
 *
 * `baseUrl` is expected to already carry the report's own filters (date
 * range, employee, …). It may therefore already contain a `?`, so the
 * separator is chosen rather than assumed.
 */
export type ReportExportFormat = 'xlsx' | 'csv' | 'pdf';

interface ReportExportMenuProps {
    /** Export endpoint including any filter query parameters. */
    baseUrl: string;
    /** Disables the trigger, e.g. before an employee has been picked. */
    disabled?: boolean;
    /**
     * Formats to offer, in order. Defaults to Excel first, matching the
     * server default for the payroll reports. The audit log passes CSV
     * first because that is both its server default and what an auditor
     * handoff or retention archive actually wants.
     */
    formats?: readonly ReportExportFormat[];
}

const DEFAULT_ORDER: readonly ReportExportFormat[] = ['xlsx', 'csv', 'pdf'];

const FORMATS: {
    format: ReportExportFormat;
    label: string;
    hint: string;
    icon: typeof FileSpreadsheet;
}[] = [
    {
        format: 'xlsx',
        label: 'Excel',
        hint: 'Formatted workbook',
        icon: FileSpreadsheet,
    },
    {
        format: 'csv',
        label: 'CSV',
        hint: 'Plain rows for import',
        icon: Table2,
    },
    {
        format: 'pdf',
        label: 'PDF',
        hint: 'Laid out for printing',
        icon: FileText,
    },
];

/**
 * Append the format to an export URL.
 *
 * Exported for direct unit testing: the separator choice is the one piece
 * of real logic here, and getting it wrong (always using `?`) would produce
 * a second question mark and silently drop the report's own filters.
 */
export function withFormat(
    baseUrl: string,
    format: ReportExportFormat,
): string {
    const separator = baseUrl.includes('?') ? '&' : '?';

    return `${baseUrl}${separator}format=${format}`;
}

export function ReportExportMenu({
    baseUrl,
    disabled,
    formats = DEFAULT_ORDER,
}: ReportExportMenuProps) {
    const items = formats
        .map((format) => FORMATS.find((entry) => entry.format === format))
        .filter((entry) => entry !== undefined);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" disabled={disabled}>
                    <Download className="mr-1 h-4 w-4" />
                    Export
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>Download as</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {items.map(({ format, label, hint, icon: Icon }) => (
                    <DropdownMenuItem key={format} asChild>
                        {/* A plain anchor, not an Inertia Link: the response is
                            a file download, not a page visit. */}
                        <a href={withFormat(baseUrl, format)} download>
                            <Icon className="mr-2 h-4 w-4" aria-hidden="true" />
                            <span className="flex flex-col">
                                <span>{label}</span>
                                <span className="text-xs text-muted-foreground">
                                    {hint}
                                </span>
                            </span>
                        </a>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
