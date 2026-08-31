import { CalendarClock } from 'lucide-react';

interface CutoverNoteProps {
    /** `books_opened_on` for the active school, or null if it has none. */
    booksOpenedOn?: string | null;
    /** Start of the range being reported, `YYYY-MM-DD`. */
    from: string;
    /** End of the range being reported, `YYYY-MM-DD`. */
    to: string;
}

/**
 * Says where a figure came from when part of it was never transacted here.
 *
 * The reports need no arithmetic change to handle a cutover snapshot — a
 * backdated entry sweeps into the opening balance like any other posting.
 * What they cannot do on their own is distinguish "this account opened at
 * ₱700,000 because of everything posted before the range" from "…because
 * ₱700,000 was carried in from the client's previous books on day one."
 * Those read identically in a column of figures and mean different things to
 * anyone reconciling them.
 *
 * Renders nothing when the school has no cutover, which is every school that
 * genuinely started from zero.
 */
export function CutoverNote({ booksOpenedOn, from, to }: CutoverNoteProps) {
    if (!booksOpenedOn) {
        return null;
    }

    // Plain string comparison is safe and correct here: all three are
    // zero-padded YYYY-MM-DD, which sorts lexicographically.
    const insideRange = booksOpenedOn >= from && booksOpenedOn <= to;
    const beforeRange = booksOpenedOn < from;

    if (!insideRange && !beforeRange) {
        return null;
    }

    return (
        <p className="flex items-start gap-2 text-sm text-muted-foreground">
            <CalendarClock className="mt-0.5 size-4 shrink-0" />
            <span>
                {beforeRange ? (
                    <>
                        Opening figures include the balances carried into these
                        books on {booksOpenedOn}.
                    </>
                ) : (
                    <>
                        This range spans {booksOpenedOn}, when the balances from
                        the previous books were carried in. Movement on that
                        date was brought forward, not transacted.
                    </>
                )}
            </span>
        </p>
    );
}
