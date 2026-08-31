<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Turns a date into the timestamps a `date` column can safely be compared to.
 *
 * The bug this exists to prevent, found in Slice 8a and easy to reintroduce
 * every time someone adds a date filter:
 *
 *   A `date` column compared against a bare `'2026-08-31'` looks correct and
 *   silently drops the last day of the range. Eloquent's `date` cast writes
 *   `Y-m-d H:i:s`, SQLite stores that verbatim, and the comparison is then a
 *   STRING compare — `'2026-08-31 00:00:00' <= '2026-08-31'` is false.
 *
 * Full timestamps work on both engines: MySQL coerces to DATETIME and widens
 * the DATE to midnight, SQLite compares the strings it actually stored. Both
 * keep the comparison a plain inequality, so a `(school_id, date)` index stays
 * usable — which `whereDate()` would forfeit.
 *
 * `CLAUDE.md` states this as an accounting invariant. It lived as two private
 * methods on `LedgerReportService`, which meant every new date filter had to
 * rediscover it; this is that rule in one place.
 */
final class DayBoundary
{
    /** Inclusive lower bound, and the exclusive boundary for "before". */
    public static function start(CarbonImmutable $date): string
    {
        return $date->startOfDay()->format('Y-m-d H:i:s');
    }

    /** Inclusive upper bound. */
    public static function end(CarbonImmutable $date): string
    {
        return $date->endOfDay()->format('Y-m-d H:i:s');
    }

    /**
     * Parse a user-supplied date, or null when it is absent or unreadable.
     *
     * Null rather than an exception: an unparseable date in a query string is
     * someone editing a URL, and a filter that silently declines to narrow is
     * better than an error page on a list view.
     */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
