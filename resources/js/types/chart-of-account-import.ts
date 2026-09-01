/**
 * The chart of accounts spreadsheet round trip.
 *
 * Must stay in lockstep with `app/Imports/ChartOfAccountImport.php` and
 * `ChartOfAccountController::importPreview()`.
 */

/** What a row will do when the import is confirmed. */
export type ChartImportAction = 'create' | 'update' | 'unchanged';

export interface ChartFieldChange {
    from: string | number | boolean | null;
    to: string | number | boolean | null;
}

export interface ChartImportRow {
    row_number: number;
    code: string | null;
    account_id: number | null;
    name: string | null;
    action: ChartImportAction;
    /** Carried as a code, not an id: the parent may be created by this file. */
    parent_code: string | null;
    /** Keyed by column; only the fields that actually move. */
    changes: Record<string, ChartFieldChange>;
    errors: string[];
}

export interface ChartImportSummary {
    row_count: number;
    create_count: number;
    update_count: number;
    unchanged_count: number;
    error_count: number;
}

/**
 * Present on the chart index only after an upload — its presence is what
 * reopens the dialog on the redirect back.
 */
export interface ChartImportPreview {
    parsed: ChartImportRow[];
    token: string;
    sourceFilename: string | null;
    summary: ChartImportSummary;
}
