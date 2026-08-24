<?php

declare(strict_types=1);

use App\Services\Accounting\DocumentNumberAllocator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * The structural half of the gapless guarantee.
 *
 * DocumentNumberAllocator refuses to issue a number outside a transaction: a
 * number taken and committed, whose document then fails to insert, burns a
 * serial the Bureau expects to see on a real form. The allocator will not do
 * it at all rather than trusting every caller to remember.
 *
 * This lives in Unit rather than Feature deliberately. tests/Pest.php applies
 * RefreshDatabase to `Feature`, which holds an open transaction for the whole
 * test — so DB::transactionLevel() is never 0 there and the guard could only
 * ever pass vacuously. Unit tests get no such wrapper, so the zero-level
 * branch is genuinely reachable.
 *
 * TestCase is applied explicitly because tests/Pest.php binds it only to
 * `Feature` and `Browser`; the allocator is resolved from the container, so
 * the app has to boot. RefreshDatabase is deliberately NOT applied — that is
 * the entire point. Nothing here touches the database: the guard is the first
 * statement in allocate() and throws before any query runs.
 */

uses(TestCase::class);

it('refuses to issue a number outside a transaction', function () {
    expect(DB::transactionLevel())->toBe(0, 'this test is only meaningful with no ambient transaction');

    expect(fn () => app(DocumentNumberAllocator::class)->allocate('sales_invoice'))
        ->toThrow(LogicException::class, 'must run inside a transaction');
});

it('names the reason, so the fix is obvious from the message', function () {
    // Whoever hits this needs to know it is about returning the number on
    // failure, not an arbitrary API restriction.
    expect(fn () => app(DocumentNumberAllocator::class)->allocate('sales_invoice'))
        ->toThrow(LogicException::class, 'instead of burning it');
});
