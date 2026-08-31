import { describe, expect, it } from 'vitest';
import {
    amountInputToCentavos,
    centavosToAmountInput,
    formatAmountInput,
    isAmountInput,
    stripGrouping,
} from '@/lib/money-input';

describe('money-input', () => {
    describe('isAmountInput', () => {
        it.each(['', '1', '1234', '1234.', '1234.5', '1234.56'])(
            'accepts %s',
            (input) => {
                expect(isAmountInput(input)).toBe(true);
            },
        );

        it.each(['1234.567', '-1', 'abc', '1,2.345'])('refuses %s', (input) => {
            expect(isAmountInput(input)).toBe(false);
        });

        it('accepts a keystroke landing inside an already-formatted box', () => {
            expect(isAmountInput('100,000.00')).toBe(true);
        });
    });

    describe('amountInputToCentavos', () => {
        it.each([
            ['', 0],
            ['.', 0],
            ['0', 0],
            ['1', 100],
            ['1234', 123_400],
            ['1234.', 123_400],
            ['1234.5', 123_450],
            ['1234.56', 123_456],
            ['100,000.00', 10_000_000],
            [' 1 000.25 ', 100_025],
        ])('reads %s as %i centavos', (input, expected) => {
            expect(amountInputToCentavos(input)).toBe(expected);
        });

        it('does not lose a centavo to binary floating point', () => {
            // `Number('0.29') * 100` is 28.999999999999996.
            expect(amountInputToCentavos('0.29')).toBe(29);
            // Never reachable through the keystroke gate; truncated rather
            // than rounded up if it arrives some other way.
            expect(amountInputToCentavos('1.005')).toBe(100);
        });

        it('clamps a negative to zero rather than flipping the ledger side', () => {
            expect(amountInputToCentavos('-5')).toBe(0);
        });
    });

    describe('formatAmountInput', () => {
        it.each([
            ['100000', '100,000.00'],
            ['1234.5', '1,234.50'],
            ['999', '999.00'],
            ['100,000.00', '100,000.00'],
        ])('formats %s as %s', (input, expected) => {
            expect(formatAmountInput(input)).toBe(expected);
        });

        it('leaves an empty box empty rather than claiming a zero was typed', () => {
            expect(formatAmountInput('')).toBe('');
            expect(formatAmountInput('  ')).toBe('');
            expect(formatAmountInput('.')).toBe('');
        });
    });

    describe('centavosToAmountInput', () => {
        it('groups thousands', () => {
            expect(centavosToAmountInput(10_000_000)).toBe('100,000.00');
        });

        it('renders zero as blank', () => {
            expect(centavosToAmountInput(0)).toBe('');
        });
    });

    it('round-trips a formatted figure back to the same centavos', () => {
        const centavos = 123_456_789;

        expect(amountInputToCentavos(centavosToAmountInput(centavos))).toBe(
            centavos,
        );
    });

    it('strips separators a spreadsheet paste brings along', () => {
        expect(stripGrouping('1,234,567.89')).toBe('1234567.89');
    });
});
