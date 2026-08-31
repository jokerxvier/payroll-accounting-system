import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CutoverNote } from '@/components/admin/cutover-note';

describe('CutoverNote', () => {
    it('says nothing for a school that started from zero', () => {
        const { container } = render(
            <CutoverNote
                booksOpenedOn={null}
                from="2026-07-01"
                to="2026-07-31"
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('attributes the opening figures when the cutover precedes the range', () => {
        render(
            <CutoverNote
                booksOpenedOn="2026-06-30"
                from="2026-07-01"
                to="2026-07-31"
            />,
        );

        expect(
            screen.getByText(/carried into these books on 2026-06-30/i),
        ).toBeInTheDocument();
    });

    it('warns when the range itself spans the cutover', () => {
        render(
            <CutoverNote
                booksOpenedOn="2026-06-30"
                from="2026-06-01"
                to="2026-06-30"
            />,
        );

        expect(
            screen.getByText(/brought forward, not transacted/i),
        ).toBeInTheDocument();
    });

    it('says nothing for a range entirely before the cutover', () => {
        const { container } = render(
            <CutoverNote
                booksOpenedOn="2026-06-30"
                from="2026-04-01"
                to="2026-04-30"
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
