/**
 * The contact spreadsheet round trip.
 *
 * Must stay in lockstep with `app/Imports/ContactImport.php` and
 * `app/Http/Controllers/Admin/Accounting/ContactImportController.php`.
 */

/** What a row will do when the import is confirmed. */
export type ContactImportAction = 'create' | 'update' | 'unchanged';

/** One field that moves, and what it moves from. */
export interface ContactFieldChange {
    from: string | number | boolean | null;
    to: string | number | boolean | null;
}

export interface ContactImportRow {
    row_number: number;
    code: string | null;
    contact_id: number | null;
    name: string | null;
    action: ContactImportAction;
    /** Keyed by column name; only the fields that actually move. */
    changes: Record<string, ContactFieldChange>;
    errors: string[];
}

export interface ContactImportSummary {
    row_count: number;
    create_count: number;
    update_count: number;
    unchanged_count: number;
    error_count: number;
}

export interface ContactImportPageProps {
    parsed?: ContactImportRow[] | null;
    token?: string | null;
    sourceFilename?: string | null;
    summary?: ContactImportSummary | null;
}
