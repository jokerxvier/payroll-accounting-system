<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Observers\AuditObserver;

/**
 * Opt a Pas\* model into the centralized audit trail.
 *
 * Adding `use Auditable;` to a model registers App\Observers\AuditObserver,
 * which writes one row to `pas_audit_logs` per created/updated/deleted event.
 *
 * Models can override auditExclude() to omit specific columns from the
 * before/after JSON payload (e.g. ephemeral or extremely large fields).
 */
trait Auditable
{
    /**
     * Boot hook auto-invoked by Eloquent's HasFactory/Model trait machinery.
     *
     * NOTE: we do not use Model::observe() here because that helper runs
     * `new static` during the model's own boot cycle, which triggers a
     * recursive bootIfNotBooted() and throws. Registering each event
     * statically with the same observer class achieves the same effect
     * without instantiating the model mid-boot.
     */
    public static function bootAuditable(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::registerModelEvent($event, AuditObserver::class.'@'.$event);
        }
    }

    /**
     * Columns to omit from audit-log before/after snapshots.
     *
     * Default: include everything. Override per-model when needed.
     *
     * @return list<string>
     */
    public function auditExclude(): array
    {
        return [];
    }
}
