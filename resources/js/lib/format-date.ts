import { format, isValid, parseISO } from 'date-fns';

/**
 * A date as a reader would write it — `27 Sep 2026`.
 *
 * Dates cross the wire as `YYYY-MM-DD` and were being printed that way on the
 * reports, which is a format for sorting, not for reading. Day first, as the
 * schools write it, and the month spelled so 03/04 cannot be read two ways.
 *
 * Anything that is not a date comes back unchanged. A heading that says
 * `not-a-date` is a bug someone can see and report; one that says
 * `Invalid Date`, or throws, is not.
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const date = parseISO(value);

    return isValid(date) ? format(date, 'd MMM yyyy') : value;
}
