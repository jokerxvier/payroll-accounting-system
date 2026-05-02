<?php

declare(strict_types=1);

use App\ValueObjects\Money;

// ---------------------------------------------------------------------
// Static factories — fromCentavos / zero
// ---------------------------------------------------------------------

it('round-trips through fromCentavos', function (): void {
    expect(Money::fromCentavos(123456)->centavos())->toBe(123456);
});

it('treats zero centavos as zero', function (): void {
    expect(Money::fromCentavos(0)->isZero())->toBeTrue();
});

it('flags a negative cent count as negative and not positive', function (): void {
    $money = Money::fromCentavos(-1);

    expect($money->isNegative())->toBeTrue()
        ->and($money->isPositive())->toBeFalse();
});

it('flags a positive cent count as positive and not negative', function (): void {
    $money = Money::fromCentavos(1);

    expect($money->isPositive())->toBeTrue()
        ->and($money->isNegative())->toBeFalse();
});

it('exposes a zero() factory', function (): void {
    expect(Money::zero()->centavos())->toBe(0);
});

// ---------------------------------------------------------------------
// Static factories — fromDecimalString happy path
// ---------------------------------------------------------------------

it('parses a standard 2dp decimal string', function (): void {
    expect(Money::fromDecimalString('1234.56')->centavos())->toBe(123456);
});

it('parses a sub-peso amount', function (): void {
    expect(Money::fromDecimalString('0.01')->centavos())->toBe(1);
});

it('parses an integer-only decimal string', function (): void {
    expect(Money::fromDecimalString('100')->centavos())->toBe(10000);
});

it('parses a negative decimal string', function (): void {
    expect(Money::fromDecimalString('-1234.56')->centavos())->toBe(-123456);
});

it('parses zero as zero', function (): void {
    expect(Money::fromDecimalString('0')->centavos())->toBe(0);
});

it('normalises -0 to zero', function (): void {
    expect(Money::fromDecimalString('-0')->centavos())->toBe(0);
});

it('parses a 1dp decimal string by padding to 2dp', function (): void {
    expect(Money::fromDecimalString('1.5')->centavos())->toBe(150);
});

// ---------------------------------------------------------------------
// Static factories — fromDecimalString rejection
// ---------------------------------------------------------------------

it('rejects fractional centavos to force explicit rounding', function (): void {
    Money::fromDecimalString('0.005');
})->throws(InvalidArgumentException::class, 'fractional centavos');

it('rejects non-numeric input', function (): void {
    Money::fromDecimalString('abc');
})->throws(InvalidArgumentException::class);

it('rejects thousands separators', function (): void {
    Money::fromDecimalString('1,234.56');
})->throws(InvalidArgumentException::class);

it('rejects empty input', function (): void {
    Money::fromDecimalString('');
})->throws(InvalidArgumentException::class);

it('rejects multiple decimal points', function (): void {
    Money::fromDecimalString('1.2.3');
})->throws(InvalidArgumentException::class);

it('rejects scientific notation', function (): void {
    Money::fromDecimalString('1e2');
})->throws(InvalidArgumentException::class);

it('rejects whitespace-padded input', function (): void {
    Money::fromDecimalString(' 1.00');
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------
// toDecimalString / __toString
// ---------------------------------------------------------------------

it('formats positive centavos as a 2dp string', function (): void {
    expect(Money::fromCentavos(123456)->toDecimalString())->toBe('1234.56');
});

it('formats zero as 0.00', function (): void {
    expect(Money::fromCentavos(0)->toDecimalString())->toBe('0.00');
});

it('formats a whole peso with two-decimal padding', function (): void {
    expect(Money::fromCentavos(-100)->toDecimalString())->toBe('-1.00');
});

it('formats a single-cent amount with leading zero', function (): void {
    expect(Money::fromCentavos(1)->toDecimalString())->toBe('0.01');
});

it('keeps __toString in sync with toDecimalString', function (): void {
    $money = Money::fromCentavos(-5042);

    expect((string) $money)->toBe($money->toDecimalString())
        ->and((string) $money)->toBe('-50.42');
});

// ---------------------------------------------------------------------
// Arithmetic — plus / minus / times
// ---------------------------------------------------------------------

it('adds two amounts', function (): void {
    expect(Money::fromCentavos(100)->plus(Money::fromCentavos(50))->centavos())->toBe(150);
});

it('subtracts two amounts and produces a negative result', function (): void {
    expect(Money::fromCentavos(100)->minus(Money::fromCentavos(150))->centavos())->toBe(-50);
});

it('multiplies by an integer scalar', function (): void {
    expect(Money::fromCentavos(100)->times(3)->centavos())->toBe(300);
});

it('multiplies by zero to produce zero', function (): void {
    expect(Money::fromCentavos(12345)->times(0)->centavos())->toBe(0);
});

it('multiplies by a negative scalar', function (): void {
    expect(Money::fromCentavos(100)->times(-2)->centavos())->toBe(-200);
});

it('returns new instances and never mutates the operands (plus)', function (): void {
    $a = Money::fromCentavos(100);
    $b = Money::fromCentavos(50);
    $sum = $a->plus($b);

    expect($a->centavos())->toBe(100)
        ->and($b->centavos())->toBe(50)
        ->and($sum->centavos())->toBe(150)
        ->and($sum)->not->toBe($a);
});

it('returns new instances and never mutates the operands (minus)', function (): void {
    $a = Money::fromCentavos(100);
    $b = Money::fromCentavos(40);
    $diff = $a->minus($b);

    expect($a->centavos())->toBe(100)
        ->and($b->centavos())->toBe(40)
        ->and($diff->centavos())->toBe(60);
});

it('returns new instances and never mutates the operand (times)', function (): void {
    $a = Money::fromCentavos(100);
    $product = $a->times(5);

    expect($a->centavos())->toBe(100)
        ->and($product->centavos())->toBe(500)
        ->and($product)->not->toBe($a);
});

// ---------------------------------------------------------------------
// dividedBy — banker's rounding (round half to even)
// ---------------------------------------------------------------------

it('rounds a half-case down to the nearest even integer (10/4 -> 2)', function (): void {
    // 2.5 → 2 (2 is even)
    expect(Money::fromCentavos(10)->dividedBy(4)->centavos())->toBe(2);
});

it('rounds a half-case up to the nearest even integer (30/4 -> 8)', function (): void {
    // 7.5 → 8 (8 is even)
    expect(Money::fromCentavos(30)->dividedBy(4)->centavos())->toBe(8);
});

it('returns the exact quotient when divisible (10/2 -> 5)', function (): void {
    expect(Money::fromCentavos(10)->dividedBy(2)->centavos())->toBe(5);
});

it('rounds away from zero when strictly more than half (11/4 -> 3)', function (): void {
    // 2.75 → 3
    expect(Money::fromCentavos(11)->dividedBy(4)->centavos())->toBe(3);
});

it('truncates toward zero when strictly less than half (9/4 -> 2)', function (): void {
    // 2.25 → 2
    expect(Money::fromCentavos(9)->dividedBy(4)->centavos())->toBe(2);
});

it("applies banker's rounding symmetrically for negative dividends (-10/4 -> -2)", function (): void {
    // -2.5 → -2 (nearest even)
    expect(Money::fromCentavos(-10)->dividedBy(4)->centavos())->toBe(-2);
});

it("applies banker's rounding symmetrically for negative dividends (-30/4 -> -8)", function (): void {
    // -7.5 → -8 (nearest even)
    expect(Money::fromCentavos(-30)->dividedBy(4)->centavos())->toBe(-8);
});

it('throws DivisionByZeroError when dividing by zero', function (): void {
    Money::fromCentavos(100)->dividedBy(0);
})->throws(DivisionByZeroError::class);

// ---------------------------------------------------------------------
// Comparison — equals / lessThan / greaterThan / boundary variants
// ---------------------------------------------------------------------

it('reports equality on identical centavos', function (): void {
    expect(Money::fromCentavos(123)->equals(Money::fromCentavos(123)))->toBeTrue();
});

it('reports inequality on different centavos', function (): void {
    expect(Money::fromCentavos(123)->equals(Money::fromCentavos(124)))->toBeFalse();
});

it('reports zero as equal to zero (reflexive)', function (): void {
    expect(Money::zero()->equals(Money::zero()))->toBeTrue();
});

it('orders positive amounts with lessThan', function (): void {
    expect(Money::fromCentavos(50)->lessThan(Money::fromCentavos(100)))->toBeTrue()
        ->and(Money::fromCentavos(100)->lessThan(Money::fromCentavos(50)))->toBeFalse()
        ->and(Money::fromCentavos(50)->lessThan(Money::fromCentavos(50)))->toBeFalse();
});

it('orders mixed-sign amounts with lessThan', function (): void {
    expect(Money::fromCentavos(-100)->lessThan(Money::fromCentavos(1)))->toBeTrue()
        ->and(Money::fromCentavos(-100)->lessThan(Money::fromCentavos(-50)))->toBeTrue();
});

it('treats equal amounts as lessThanOrEqual', function (): void {
    expect(Money::fromCentavos(100)->lessThanOrEqual(Money::fromCentavos(100)))->toBeTrue()
        ->and(Money::fromCentavos(101)->lessThanOrEqual(Money::fromCentavos(100)))->toBeFalse();
});

it('orders positive amounts with greaterThan', function (): void {
    expect(Money::fromCentavos(100)->greaterThan(Money::fromCentavos(50)))->toBeTrue()
        ->and(Money::fromCentavos(50)->greaterThan(Money::fromCentavos(100)))->toBeFalse()
        ->and(Money::fromCentavos(100)->greaterThan(Money::fromCentavos(100)))->toBeFalse();
});

it('treats equal amounts as greaterThanOrEqual', function (): void {
    expect(Money::fromCentavos(100)->greaterThanOrEqual(Money::fromCentavos(100)))->toBeTrue()
        ->and(Money::fromCentavos(99)->greaterThanOrEqual(Money::fromCentavos(100)))->toBeFalse();
});

// ---------------------------------------------------------------------
// Round-trip — toDecimalString -> fromDecimalString
// ---------------------------------------------------------------------

it('round-trips through toDecimalString and fromDecimalString', function (): void {
    foreach ([0, 1, -1, 100, -100, 99, -99, 123456, -123456, 999999] as $cents) {
        $money = Money::fromCentavos($cents);
        $roundTripped = Money::fromDecimalString($money->toDecimalString());

        expect($roundTripped->centavos())->toBe($cents);
    }
});
