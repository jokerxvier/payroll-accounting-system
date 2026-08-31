<?php

declare(strict_types=1);

use App\Support\ContributionLedger;

/**
 * @param  array<int, array{0: string, 1: int}>  $pairs
 * @return list<array{code: string, amount: int}>
 */
function lines(array $pairs): array
{
    return array_map(
        static fn (array $pair): array => ['code' => $pair[0], 'amount' => $pair[1]],
        $pairs,
    );
}

it('adds the employee and employer shares that reached the same agency', function () {
    $rows = ContributionLedger::build(
        lines([['SSS_EMPLOYEE', 175_000]]),
        lines([['SSS_EMPLOYER', 315_000]]),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('SSS')
        ->and($rows[0]['yours'])->toBe(175_000)
        ->and($rows[0]['school'])->toBe(315_000)
        ->and($rows[0]['credited'])->toBe(490_000);
});

it("counts Employees' Compensation as money reaching SSS", function () {
    // EC is a separate line with its own code, but it is still remitted to
    // SSS in the employee's name — a ledger that dropped it would understate
    // what the agency holds.
    $rows = ContributionLedger::build(
        lines([['SSS_EMPLOYEE', 175_000]]),
        lines([['SSS_EMPLOYER', 315_000], ['SSS_EMPLOYER_EC', 3_000]]),
    );

    expect($rows[0]['school'])->toBe(318_000)
        ->and($rows[0]['credited'])->toBe(493_000);
});

it('leaves withholding tax out entirely', function () {
    // Remitted to the BIR, but it buys no entitlement and is credited to no
    // record the employee can draw on.
    $rows = ContributionLedger::build(
        lines([['BIR_WITHHOLDING_EMPLOYEE', 170_130]]),
        [],
    );

    expect($rows)->toBeEmpty();
});

it('omits an agency that received nothing this period', function () {
    $rows = ContributionLedger::build(
        lines([['SSS_EMPLOYEE', 175_000]]),
        [],
    );

    expect(array_column($rows, 'label'))->toBe(['SSS']);
});

it('lists the agencies in a fixed order regardless of line order', function () {
    $rows = ContributionLedger::build(
        lines([
            ['PAGIBIG_EMPLOYEE', 20_000],
            ['PHILHEALTH_EMPLOYEE', 120_000],
            ['SSS_EMPLOYEE', 175_000],
        ]),
        [],
    );

    expect(array_column($rows, 'label'))->toBe(['SSS', 'PhilHealth', 'Pag-IBIG']);
});

it('reads the side from the code, not from which list a line arrived in', function () {
    // One merged list still splits correctly, so a caller cannot produce a
    // ledger that credits the school's share to the employee.
    $rows = ContributionLedger::build(
        lines([['SSS_EMPLOYEE', 175_000], ['SSS_EMPLOYER', 315_000]]),
        [],
    );

    expect($rows[0]['yours'])->toBe(175_000)
        ->and($rows[0]['school'])->toBe(315_000);
});

it('ignores a line with no code at all', function () {
    $rows = ContributionLedger::build([['amount' => 500_00]], []);

    expect($rows)->toBeEmpty();
});

it('totals what every agency was credited', function () {
    $rows = ContributionLedger::build(
        lines([['SSS_EMPLOYEE', 175_000], ['PHILHEALTH_EMPLOYEE', 120_000]]),
        lines([['SSS_EMPLOYER', 315_000], ['PHILHEALTH_EMPLOYER', 120_000]]),
    );

    expect(ContributionLedger::total($rows))->toBe(730_000);
});

it('totals zero when nothing was contributed', function () {
    expect(ContributionLedger::total([]))->toBe(0);
});
