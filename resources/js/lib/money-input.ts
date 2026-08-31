/**
 * The text side of a peso amount box.
 *
 * Amounts travel as integer centavos and are *displayed* by `<Money>`, which
 * groups thousands through `Intl.NumberFormat('en-PH')`. An input box had no
 * equivalent: every accounting form kept its own `(centavos / 100).toFixed(2)`
 * and rendered a six-figure receipt as `100000`, which reads as a different
 * number at a glance than the `₱100,000.00` sitting two rows below it in the
 * allocation table.
 *
 * The split here is deliberate:
 *
 *   - **While typing**, the box holds exactly what was typed. Regrouping on
 *     each keystroke moves the caret, so typing into the middle of a figure
 *     jumps to the end after every character.
 *   - **On blur**, the figure is grouped and padded to two places, which is
 *     also the moment the operator has stopped and wants to read it back.
 *
 * Parsing therefore has to accept both forms, and does: separators are
 * stripped before anything else looks at the string.
 */

/**
 * Digits, at most one decimal point, at most two places after it.
 *
 * Applied as a gate on each keystroke rather than sanitising afterwards, so a
 * rejected character never appears. That also covers the minus sign: an
 * amount box moves one side by a positive figure, so a negative is not a
 * value to correct later, it is a keystroke to refuse now.
 *
 * A trailing point ("1234.") passes — it is a legitimate half-finished entry,
 * and refusing it would eat the key the moment it was pressed.
 */
const AMOUNT_PATTERN = /^\d*\.?\d{0,2}$/;

const GROUPED = new Intl.NumberFormat('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * Drop the thousands separators a formatted box carries, so the same string
 * can be re-typed into, pasted, or parsed. Spaces go too — they arrive from
 * a paste out of a spreadsheet.
 */
export function stripGrouping(input: string): string {
    return input.replace(/[,\s]/g, '');
}

/**
 * Whether this keystroke may land in an amount box.
 *
 * Tested against the ungrouped form, so a caret placed inside an already
 * formatted `100,000.00` does not have its next digit refused.
 */
export function isAmountInput(input: string): boolean {
    return AMOUNT_PATTERN.test(stripGrouping(input));
}

/** Integer centavos from whatever is in the box. Never NaN. */
export function amountInputToCentavos(input: string): number {
    const cleaned = stripGrouping(input).trim();

    if (cleaned === '' || cleaned === '.') {
        return 0;
    }

    // Split rather than multiply: `Number('0.29') * 100` is 28.999… and
    // `Math.round` only hides that.
    //
    // The keystroke gate has already capped the fraction at two places, so
    // the slice only bites on a string that arrived some other way — and
    // truncating a third place is the safer reading of one, since rounding a
    // figure nobody could have typed up to the next centavo invents money.
    const [whole = '', fraction = ''] = cleaned.split('.');
    const centavos =
        Number(whole || '0') * 100 +
        Number(fraction.slice(0, 2).padEnd(2, '0'));

    // Clamped, not just NaN-guarded: the keystroke gate refuses a minus sign,
    // so a negative here means the string bypassed the box, and an amount box
    // that can return a negative would flip which side of the ledger a line
    // moves.
    return Number.isNaN(centavos) || centavos < 0 ? 0 : centavos;
}

/**
 * The grouped, two-place form for display: `100000` → `100,000.00`.
 *
 * An empty box stays empty rather than becoming `0.00` — a blank amount is
 * "not filled in yet", and printing a zero into it claims the operator
 * entered one.
 */
export function formatAmountInput(input: string): string {
    const cleaned = stripGrouping(input).trim();

    if (cleaned === '' || cleaned === '.') {
        return '';
    }

    return GROUPED.format(amountInputToCentavos(cleaned) / 100);
}

/** The same, from the stored figure. Zero renders blank, for the reason above. */
export function centavosToAmountInput(centavos: number): string {
    return centavos === 0 ? '' : GROUPED.format(centavos / 100);
}
