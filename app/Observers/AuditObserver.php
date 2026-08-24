<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pas\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Generic append-only audit observer for any model that uses the
 * App\Concerns\Auditable trait.
 *
 * Payload conventions:
 *  - created: before = null, after = full attribute snapshot
 *  - updated: before / after carry ONLY the columns whose values changed.
 *             This keeps each row small and produces a focused diff that's
 *             easy to render in a future audit-log viewer. The full prior
 *             state can always be reconstructed by walking earlier rows.
 *  - deleted: before = full original snapshot, after = null
 *
 * Actor: pulled from Auth::id() if a user is logged in, else null. Anonymous
 * mutations (artisan tinker, queued jobs without an authenticated context,
 * seeders) are still recorded — actor_id is simply null.
 *
 * Request context (ip, user_agent) is read from the request() helper if a
 * request is bound to the container; otherwise both fields are null.
 *
 * Tenant: resolved from the audited record where it has one, otherwise from
 * the current tenant — see {@see self::resolveSchoolId()}.
 */
final class AuditObserver
{
    public function created(Model $model): void
    {
        $exclude = $this->excludedColumns($model);

        $this->record(
            model: $model,
            action: 'created',
            before: null,
            after: $this->filter($model->getAttributes(), $exclude),
        );
    }

    public function updated(Model $model): void
    {
        $exclude = $this->excludedColumns($model);

        $dirty = $model->getDirty();

        if ($dirty === []) {
            return;
        }

        $original = array_intersect_key($model->getOriginal(), $dirty);

        $this->record(
            model: $model,
            action: 'updated',
            before: $this->filter($original, $exclude),
            after: $this->filter($dirty, $exclude),
        );
    }

    public function deleted(Model $model): void
    {
        $exclude = $this->excludedColumns($model);

        $this->record(
            model: $model,
            action: 'deleted',
            before: $this->filter($model->getOriginal(), $exclude),
            after: null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Model $model, string $action, ?array $before, ?array $after): void
    {
        $request = function_exists('request') ? request() : null;

        AuditLog::query()->create([
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'school_id' => $this->resolveSchoolId($model),
            'actor_id' => Auth::id(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $exclude
     * @return array<string, mixed>
     */
    private function filter(array $attributes, array $exclude): array
    {
        if ($exclude === []) {
            return $attributes;
        }

        return array_diff_key($attributes, array_flip($exclude));
    }

    /**
     * @return list<string>
     */
    private function excludedColumns(Model $model): array
    {
        if (method_exists($model, 'auditExclude')) {
            /** @var list<string> $excluded */
            $excluded = $model->auditExclude();

            return $excluded;
        }

        return [];
    }

    /**
     * Which tenant this audit row belongs to.
     *
     * `AuditLog` uses `BelongsToTenant`, which auto-fills `school_id` from
     * `Tenant::current()` when the attribute is left null. Resolving it from
     * the audited record instead makes the row depend on the data rather
     * than on ambient request state — the two agree in normal operation,
     * because you can only mutate your own tenant's rows, but the explicit
     * form does not quietly misfile anything if that ever stops holding.
     *
     * Returning null keeps the trait's current-tenant fallback in place (it
     * only fills when the attribute is null), which is what we want for
     * models that are not tenant-scoped.
     *
     * A School deliberately falls into that fallback rather than being filed
     * under itself. A tenant registry row is not tenant-scoped *data* — it
     * IS the tenant — and filing its audit under its own id would create a
     * self-reference that `pas_audit_logs.school_id`'s restrictOnDelete then
     * uses to block ever deleting that school. `auditable_type` plus
     * `auditable_id` already identify the school precisely.
     */
    private function resolveSchoolId(Model $model): ?int
    {
        $schoolId = $model->getAttribute('school_id');

        return $schoolId === null ? null : (int) $schoolId;
    }
}
