import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import JournalReport from '@/pages/admin/accounting/reports/journal-report';
import type { JournalReportEntry } from '@/types/ledger-report';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        get: vi.fn(),
        processing: false,
    }),
}));

function entry(
    overrides: Partial<JournalReportEntry> = {},
): JournalReportEntry {
    return {
        id: 1,
        entry_number: 'JE-2026-00001',
        date: '2026-08-15',
        reference: 'INV-0001',
        narration: 'Tuition collected',
        source_type: 'invoice',
        is_reversal: false,
        total_debit_centavos: 500_000,
        total_credit_centavos: 500_000,
        lines: [
            {
                id: 1,
                account_code: '1100',
                account_name: 'Cash on Hand',
                description: null,
                debit_centavos: 500_000,
                credit_centavos: 0,
            },
            {
                id: 2,
                account_code: '4100',
                account_name: 'Tuition Fee Income',
                description: null,
                debit_centavos: 0,
                credit_centavos: 500_000,
            },
        ],
        ...overrides,
    };
}

function renderPage(entries: JournalReportEntry[]) {
    const debit = entries.reduce((sum, e) => sum + e.total_debit_centavos, 0);
    const credit = entries.reduce((sum, e) => sum + e.total_credit_centavos, 0);

    return render(
        <JournalReport
            filters={{ from: '2026-08-01', to: '2026-08-31' }}
            entries={entries}
            totals={{
                entry_count: entries.length,
                debit_centavos: debit,
                credit_centavos: credit,
            }}
        />,
    );
}

describe('journal report', () => {
    it('renders one row per line', () => {
        renderPage([entry()]);

        const body = screen.getByRole('table').querySelector('tbody');
        expect(body?.querySelectorAll('tr')).toHaveLength(2);
    });

    it('prints the entry columns once per transaction', () => {
        // Repeating the date and number on every line turns a journal into a
        // list of amounts; the grouping is what makes it readable.
        renderPage([entry()]);

        expect(screen.getAllByText('JE-2026-00001')).toHaveLength(1);
        expect(screen.getAllByText('2026-08-15')).toHaveLength(1);
    });

    it('marks a reversing entry', () => {
        renderPage([entry({ is_reversal: true })]);

        expect(screen.getByText('reversal')).toBeInTheDocument();
    });

    it('counts entries, not lines', () => {
        renderPage([entry(), entry({ id: 2, entry_number: 'JE-2026-00002' })]);

        expect(screen.getByText('2 entries')).toBeInTheDocument();
    });

    it('uses the singular for one entry', () => {
        renderPage([entry()]);

        expect(screen.getByText('1 entry')).toBeInTheDocument();
    });

    it('explains an empty range', () => {
        renderPage([]);

        const table = screen.getByRole('table');
        expect(
            within(table).getByText(/No entry was posted with a date/),
        ).toBeInTheDocument();
    });
});
