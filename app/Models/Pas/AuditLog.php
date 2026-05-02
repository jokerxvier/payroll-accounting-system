<?php

declare(strict_types=1);

namespace App\Models\Pas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Append-only audit trail entry for any auditable Pas\* model.
 *
 * Rows are written by App\Observers\AuditObserver in response to model
 * created/updated/deleted events. The `before` and `after` JSON columns capture
 * the relevant slice of the auditable's attributes:
 *
 *  - created: before = null, after = full attribute snapshot
 *  - updated: before = original values for ONLY the changed columns,
 *             after  = the new values for ONLY the changed columns
 *  - deleted: before = full attribute snapshot, after = null
 *
 * The table has no `updated_at`; entries are immutable. Both update() and
 * delete() are overridden to throw LogicException as a defense-in-depth
 * measure against accidental future mutations of the audit log.
 */
final class AuditLog extends Model
{
    // Lives on the default connection (mysql in dev/prod, sqlite in tests),
    // matching the rest of the App\Models\Pas\* models. The `lms` read-only
    // connection is explicitly NOT used for audit-log writes.
    protected $table = 'pas_audit_logs';

    /**
     * The migration declares only `created_at` (with DEFAULT CURRENT_TIMESTAMP)
     * and no `updated_at`. We disable Eloquent's automatic timestamping and let
     * the observer set `created_at` explicitly so tests can freeze time.
     */
    public $timestamps = false;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'actor_id',
        'action',
        'before',
        'after',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'auditable_id' => 'integer',
            'actor_id' => 'integer',
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Polymorphic relation back to the auditable model.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Audit log entries are append-only. Block direct updates.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('Audit logs are append-only.');
    }

    /**
     * Audit log entries are append-only. Block deletion.
     */
    public function delete(): bool
    {
        throw new LogicException('Audit logs are append-only.');
    }
}
