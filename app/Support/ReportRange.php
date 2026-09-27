<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Accounting\FiscalYear;
use Carbon\CarbonImmutable;

/**
 * The dates a report opens on, from a preset or a pair the reader typed.
 *
 * Shared by the accounting dashboard and the Income Statement so "This
 * quarter" cannot mean two different things on two pages.
 */
final class ReportRange
{
    public const PRESET_MONTH = 'month';

    public const PRESET_QUARTER = 'quarter';

    public const PRESET_YEAR = 'year';

    public const PRESET_CUSTOM = 'custom';

    /** @var list<string> */
    public const PRESETS = [
        self::PRESET_MONTH,
        self::PRESET_QUARTER,
        self::PRESET_YEAR,
        self::PRESET_CUSTOM,
    ];

    /**
     * Today, where the schools are.
     *
     * Manila, not UTC. `config/app.php` is UTC while the schools are UTC+8,
     * so for the eight hours after midnight local, `now()` is still
     * yesterday — and on the first of the month that makes "This month"
     * resolve to the whole of the previous one. Same correction
     * `GenerateRecurringInvoices` makes, for the same reason: a date the
     * operator reads off a wall clock has to mean their date.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Manila')->startOfDay();
    }

    /** The preset asked for, or the fiscal year when it is not one. */
    public static function preset(mixed $value): string
    {
        return is_string($value) && in_array($value, self::PRESETS, true)
            ? $value
            : self::PRESET_YEAR;
    }

    /**
     * Defaults to the school's current fiscal year, which is what a head
     * teacher means by "this year" — a school whose year runs June to March
     * would be shown ten months of it by a calendar default.
     *
     * An inverted custom range is swapped rather than refused: someone who
     * picked the dates the wrong way round meant the range between them. An
     * unreadable one falls back to the fiscal year — a filter that declines
     * to narrow beats an error page.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function resolve(string $preset, mixed $from, mixed $to, FiscalYear $fiscalYear): array
    {
        $today = self::today();

        if ($preset === self::PRESET_MONTH) {
            return [$today->startOfMonth(), $today->endOfMonth()->startOfDay()];
        }

        if ($preset === self::PRESET_QUARTER) {
            return [$today->firstOfQuarter(), $today->lastOfQuarter()];
        }

        if ($preset === self::PRESET_YEAR) {
            return $fiscalYear->currentRange($today);
        }

        $from = DayBoundary::parse($from);
        $to = DayBoundary::parse($to);

        if ($from === null || $to === null) {
            return $fiscalYear->currentRange($today);
        }

        $from = $from->startOfDay();
        $to = $to->startOfDay();

        return $to->lessThan($from) ? [$to, $from] : [$from, $to];
    }
}
