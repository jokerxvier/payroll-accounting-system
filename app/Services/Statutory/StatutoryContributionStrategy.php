<?php

declare(strict_types=1);

namespace App\Services\Statutory;

use App\ValueObjects\Money;
use InvalidArgumentException;

/**
 * Contract every statutory-contribution algorithm implements.
 *
 * The resolver loads the active `StatutoryContribution` row for a (code, date)
 * pair, then dispatches to the strategy whose key matches `row.algorithm`.
 * The strategy reads the polymorphic `rules` JSON and returns a typed
 * {@see StatutoryContributionResult} — no SQL, no Eloquent, no clock access.
 *
 * Strategies are stateless and safe to register as singletons.
 */
interface StatutoryContributionStrategy
{
    /**
     * Compute employee and employer shares for the given salary `$input`.
     *
     * @param  array<string, mixed>  $rules  Algorithm-specific rule payload.
     * @param  array<string, mixed>  $context  Per-payroll-run context (e.g. `period_type`).
     */
    public function compute(Money $input, array $rules, array $context = []): StatutoryContributionResult;

    /**
     * Verify that `$rules` matches the shape this strategy expects.
     * Called from the admin save path so a bad row never reaches production.
     *
     * @param  array<string, mixed>  $rules
     *
     * @throws InvalidArgumentException When the rules shape is invalid.
     */
    public function validateRules(array $rules): void;
}
