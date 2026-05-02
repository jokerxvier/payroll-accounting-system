<?php

declare(strict_types=1);

namespace App\ValueObjects;

use DivisionByZeroError;
use InvalidArgumentException;
use Stringable;

/**
 * Immutable money value object that stores amounts as integer centavos
 * (1/100 of one Philippine peso). Currency is implicit PHP for v1; an
 * ISO 4217 dimension can be added later without breaking callers.
 *
 * Every operation returns a NEW instance — `Money` is referentially
 * transparent and safe to share across threads/requests. Construction
 * is forced through static factories so all callers go through one of
 * two well-defined entry points (`fromCentavos`, `fromDecimalString`).
 *
 * The class deliberately rejects fractional centavos at parse time:
 * `Money::fromDecimalString('0.005')` throws. Callers that need to
 * round MUST do so explicitly via `dividedBy()`, which uses banker's
 * rounding (round half to even) — the rounding mode mandated by
 * `rules/CODING_STANDARDS_LARAVEL.md` §4 for tax math.
 */
final readonly class Money implements Stringable
{
    private function __construct(
        private int $cents,
    ) {}

    /**
     * Build a Money from a raw integer count of centavos.
     *
     * Negative values are allowed (used to represent debits / refunds
     * / reversing entries).
     */
    public static function fromCentavos(int $cents): self
    {
        return new self($cents);
    }

    /**
     * Parse a strict 2-decimal-place string (e.g. `"1234.56"`) into a
     * Money. Integer-only strings (`"100"`) and negatives (`"-1234.56"`)
     * are accepted; `"-0"` normalises to `0` cents.
     *
     * Strings with more than two decimal places, thousands separators,
     * scientific notation, or non-numeric characters are rejected with
     * an `InvalidArgumentException` — the value object never silently
     * truncates or rounds. Callers who need to round must do so
     * explicitly through arithmetic on a Money.
     *
     * @throws InvalidArgumentException When the input is malformed or
     *                                  contains fractional centavos.
     */
    public static function fromDecimalString(string $decimal): self
    {
        if ($decimal === '') {
            throw new InvalidArgumentException(
                'Money::fromDecimalString requires a non-empty numeric string.'
            );
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException(
                "Money::fromDecimalString cannot parse '{$decimal}': expected an optional minus sign followed by digits and at most two decimal places."
            );
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = $matches[2];
        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > 2) {
            throw new InvalidArgumentException(
                'Money::fromDecimalString does not accept fractional centavos; use Money::dividedBy() to round explicitly.'
            );
        }

        $fraction = str_pad($fraction, 2, '0', STR_PAD_RIGHT);

        $cents = $sign * ((int) $whole * 100 + (int) $fraction);

        return new self($cents);
    }

    /**
     * Convenience factory for a zero amount.
     */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Sum of two amounts.
     */
    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    /**
     * Difference `$this - $other`. Result may be negative.
     */
    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    /**
     * Multiply by an integer scalar. Pure integer math, no rounding
     * involved — overflow into a non-integral result is impossible
     * by construction.
     */
    public function times(int $multiplier): self
    {
        return new self($this->cents * $multiplier);
    }

    /**
     * Divide by an integer scalar using **banker's rounding**
     * (round half to even). Halfway cases (e.g. `10 / 4 = 2.5`)
     * round to the nearest even integer.
     *
     * Rationale: banker's rounding is the rule mandated by BIR for
     * withholding tax computations and is preferred for any repeated
     * accumulation because it has no statistical bias.
     *
     * @throws DivisionByZeroError When `$divisor` is `0`.
     */
    public function dividedBy(int $divisor): self
    {
        if ($divisor === 0) {
            throw new DivisionByZeroError('Money::dividedBy cannot divide by zero.');
        }

        $quotient = intdiv($this->cents, $divisor);
        $remainder = $this->cents % $divisor;

        if ($remainder === 0) {
            return new self($quotient);
        }

        // Compare 2 * |remainder| against |divisor| to detect the
        // halfway case without touching floats.
        $twiceRemainder = abs($remainder) * 2;
        $absDivisor = abs($divisor);

        $sign = (($this->cents < 0) xor ($divisor < 0)) ? -1 : 1;

        if ($twiceRemainder < $absDivisor) {
            // Strictly less than half — truncation toward zero is the
            // correct nearest integer; intdiv() already gave us that.
            return new self($quotient);
        }

        if ($twiceRemainder > $absDivisor) {
            // Strictly more than half — round away from zero.
            return new self($quotient + $sign);
        }

        // Exactly halfway — round to the nearest even integer.
        if ($quotient % 2 === 0) {
            return new self($quotient);
        }

        return new self($quotient + $sign);
    }

    /**
     * Raw integer count of centavos. The single primitive used when
     * crossing a boundary that doesn't speak Money (DB column,
     * serialiser, JSON payload).
     */
    public function centavos(): int
    {
        return $this->cents;
    }

    /**
     * Format as a fixed-2-decimal-place string suitable for display
     * or for round-tripping through `fromDecimalString`. No thousands
     * separator; negatives are prefixed with a literal `-`.
     */
    public function toDecimalString(): string
    {
        $negative = $this->cents < 0;
        $abs = $negative ? -$this->cents : $this->cents;

        $whole = intdiv($abs, 100);
        $fraction = $abs % 100;

        $formatted = sprintf('%d.%02d', $whole, $fraction);

        return $negative ? '-'.$formatted : $formatted;
    }

    /**
     * True iff the amount is exactly zero centavos.
     */
    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    /**
     * True iff the amount is strictly less than zero. Zero is NOT
     * considered negative.
     */
    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    /**
     * True iff the amount is strictly greater than zero. Zero is NOT
     * considered positive.
     */
    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    /**
     * Equality on centavos. Reflexive on zero.
     */
    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    /**
     * Strict less-than comparison.
     */
    public function lessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    /**
     * Less-than-or-equal comparison.
     */
    public function lessThanOrEqual(self $other): bool
    {
        return $this->cents <= $other->cents;
    }

    /**
     * Strict greater-than comparison.
     */
    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    /**
     * Greater-than-or-equal comparison.
     */
    public function greaterThanOrEqual(self $other): bool
    {
        return $this->cents >= $other->cents;
    }

    /**
     * Debug-friendly cast: identical to `toDecimalString()`. Calling
     * `(string) $money` will never throw and never lose precision.
     */
    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
