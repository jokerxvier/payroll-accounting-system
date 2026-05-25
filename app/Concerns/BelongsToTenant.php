<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Pas\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Auto-scopes a Pas\* model's queries to the current tenant and auto-fills
 * the `school_id` column on creation.
 *
 * Resolution semantics:
 *   - When a tenant is current (via Spatie's resolution pipeline), every
 *     query for the model is filtered with `WHERE school_id = {current_id}`.
 *   - When NO tenant is current (e.g., console commands without
 *     `tenants:artisan`, queue jobs that opt out of tenant awareness, raw
 *     migrations), the scope is skipped entirely. This keeps console and
 *     test bootstrap code working.
 *   - Existing escape hatch `Model::withoutGlobalScopes()` still bypasses
 *     the scope when needed (super-admin reports, etc.).
 *
 * The auto-fill on `creating` only sets `school_id` when:
 *   1. There IS a current tenant
 *   2. The attribute hasn't been set explicitly by the caller
 * This means callers can still override (e.g., super-admin reports that
 * write to a specific school), and console code without a tenant context
 * just doesn't auto-fill (defer to whatever the caller passes).
 *
 * Apply via `use BelongsToTenant;` on any Pas\* model whose table received
 * `school_id` in D.1 (or in the catalog conversion plan landed at
 * /Users/jdev/.claude/plans/what-is-next-in-joyful-rabbit.md). The
 * `StatutoryContribution` catalog intentionally does NOT use this trait —
 * PH BIR/SSS/PhilHealth/Pag-IBIG rates are national law, so duplicating per
 * tenant is waste. `Allowance` and `DeductionType` are now per-tenant
 * (each school maintains its own catalog, seeded from the default school's
 * catalog at school creation).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('belongsToTenant', function (Builder $query): void {
            $tenant = Tenant::current();
            if ($tenant === null) {
                return;
            }
            // Use the qualified column name so it works inside JOINs without
            // ambiguity errors (e.g., when Eloquent eager-loads relations).
            $query->where(
                $query->getModel()->qualifyColumn('school_id'),
                $tenant->getKey(),
            );
        });

        static::creating(function ($model): void {
            if ($model->school_id !== null) {
                return;
            }
            $tenant = Tenant::current();
            if ($tenant === null) {
                return;
            }
            $model->school_id = $tenant->getKey();
        });
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }
}
