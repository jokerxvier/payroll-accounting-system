import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    ReportExportMenu,
    withFormat,
} from '@/components/admin/report-export-menu';

describe('withFormat', () => {
    it('uses ? when the base url carries no query string', () => {
        expect(withFormat('/admin/reports/payroll-summary/export', 'pdf')).toBe(
            '/admin/reports/payroll-summary/export?format=pdf',
        );
    });

    it('uses & when the base url already carries filters', () => {
        // Always appending "?format=..." would produce a second question
        // mark and the report's own filters would be dropped.
        expect(
            withFormat(
                '/admin/reports/payroll-summary/export?from=2026-05-01&to=2026-05-31',
                'csv',
            ),
        ).toBe(
            '/admin/reports/payroll-summary/export?from=2026-05-01&to=2026-05-31&format=csv',
        );
    });

    it('builds a url for every format the reports accept', () => {
        const base = '/admin/reports/employee-history/export?employee=42';

        expect(withFormat(base, 'xlsx')).toContain('format=xlsx');
        expect(withFormat(base, 'csv')).toContain('format=csv');
        expect(withFormat(base, 'pdf')).toContain('format=pdf');
    });
});

describe('ReportExportMenu', () => {
    it('renders an enabled trigger by default', () => {
        render(
            <ReportExportMenu baseUrl="/admin/reports/payroll-summary/export" />,
        );

        expect(screen.getByRole('button', { name: /export/i })).toBeEnabled();
    });

    it('can be disabled before the report has enough input to export', () => {
        render(
            <ReportExportMenu
                baseUrl="/admin/reports/employee-history/export"
                disabled
            />,
        );

        expect(screen.getByRole('button', { name: /export/i })).toBeDisabled();
    });
});
