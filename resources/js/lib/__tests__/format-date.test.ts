import { describe, expect, it } from 'vitest';
import { formatDate } from '@/lib/format-date';

describe('formatDate', () => {
    it('writes a date day first with the month spelled', () => {
        expect(formatDate('2026-09-27')).toBe('27 Sep 2026');
        expect(formatDate('2026-03-04')).toBe('4 Mar 2026');
    });

    it('does not shift the day across a timezone', () => {
        // A bare date parsed as UTC midnight prints as the day before
        // anywhere west of Greenwich. parseISO reads it as local.
        expect(formatDate('2026-01-01')).toBe('1 Jan 2026');
        expect(formatDate('2028-02-29')).toBe('29 Feb 2028');
    });

    it('returns what it was given when that is not a date', () => {
        expect(formatDate('not-a-date')).toBe('not-a-date');
        expect(formatDate('2026-13-45')).toBe('2026-13-45');
    });

    it('prints nothing for nothing', () => {
        expect(formatDate('')).toBe('');
        expect(formatDate(null)).toBe('');
        expect(formatDate(undefined)).toBe('');
    });
});
