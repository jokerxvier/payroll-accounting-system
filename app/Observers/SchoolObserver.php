<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pas\School;
use Illuminate\Support\Facades\DB;

/**
 * Auto-clones the default school's per-tenant catalog rows
 * (`pas_allowances`, `pas_deduction_types`) onto every newly-created school.
 *
 * Hooked on `created` (not `creating`) because we need the new school's id
 * to populate `school_id` on the cloned rows.
 *
 * Idempotency:
 *   - The clone is gated per-table on the new school having ZERO rows in
 *     that catalog. If a caller pre-seeded the new school with custom rows
 *     before the observer fires, those custom rows are preserved.
 *   - The default-school lookup is by `slug = 'default'`. If the default
 *     school doesn't exist yet (fresh-bootstrap dev / test env), we skip
 *     silently — same defensive pattern the Stage A migration uses.
 *   - The observer fires when the default school itself is created (by the
 *     SchoolSeeder); we no-op when `new->id == default->id` so we don't
 *     try to clone default's catalog onto itself.
 *
 * Why an observer (not an action invoked from the controller):
 *   - The observer fires on every `School::create()` — controllers,
 *     factories, seeders, tinker. We don't have to remember to call an
 *     action at every creation site.
 *
 * Why raw DB inserts (not Eloquent create):
 *   - The catalog models now use `BelongsToTenant`, whose `creating` hook
 *     auto-fills `school_id` from `Tenant::current()`. If we used Eloquent
 *     `create()` here, the rows would land on the *current* tenant's id,
 *     not on the *new* school's id. Raw inserts via the query builder
 *     bypass the trait so we can write the explicit target `school_id`.
 *   - Bypassing the model also means `Auditable` does not log these rows
 *     individually — the observer's job is bulk-clone, not user-driven
 *     edits, and a cloned row's history starts at the operator's first
 *     edit (which DOES go through Eloquent and IS audited).
 *
 * The whole clone runs in a single transaction so a partial failure
 * (e.g., disk full mid-second-table) doesn't leave the new school with
 * one catalog populated and the other empty.
 */
final class SchoolObserver
{
    public function created(School $school): void
    {
        $defaultSchool = School::query()
            ->withoutGlobalScopes()
            ->where('slug', 'default')
            ->first();

        // Fresh-bootstrap env (no default seeded yet — happens during
        // SchoolSeeder::run() itself when the default is created). Skip
        // silently; future creations will find the default and clone.
        if ($defaultSchool === null) {
            return;
        }

        // Don't try to clone default's catalogs onto itself when this
        // observer fires for the default-school creation event.
        if ($defaultSchool->getKey() === $school->getKey()) {
            return;
        }

        $catalogTables = ['pas_allowances', 'pas_deduction_types'];

        DB::transaction(function () use ($defaultSchool, $school, $catalogTables): void {
            $now = now();

            foreach ($catalogTables as $table) {
                // Idempotency: only clone when the target school has zero
                // rows in this catalog. A re-fire (e.g. on the same row in
                // a tap-driven test, or a developer running the same seed
                // twice) is a no-op.
                $existingCount = DB::table($table)
                    ->where('school_id', $school->getKey())
                    ->count();

                if ($existingCount > 0) {
                    continue;
                }

                $defaultRows = DB::table($table)
                    ->where('school_id', $defaultSchool->getKey())
                    ->get();

                if ($defaultRows->isEmpty()) {
                    continue;
                }

                $insertRows = $defaultRows->map(function (object $row) use ($school, $now): array {
                    $clone = (array) $row;
                    unset($clone['id']);
                    $clone['school_id'] = $school->getKey();
                    $clone['created_at'] = $now;
                    $clone['updated_at'] = $now;

                    return $clone;
                })->all();

                DB::table($table)->insert($insertRows);
            }
        });
    }
}
