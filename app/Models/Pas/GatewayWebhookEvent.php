<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One webhook delivery, and what we did about it.
 *
 * The unique on `(provider, external_event_id)` is the idempotency
 * guarantee — both gateways retry until they get a 2xx, and both can deliver
 * the same event again after a success. Without this a retry books a second
 * payment and the invoice is paid twice.
 *
 * Deliberately NOT `BelongsToTenant`: a delivery whose signature fails or
 * whose school cannot be determined still has to be recorded, and the tenant
 * scope would either hide it or refuse to write it. `school_id` is therefore
 * set explicitly when it is known and left null when it is not — those null
 * rows being exactly the ones worth looking at after an incident.
 *
 * It IS `Auditable` — every persisted `pas_*` model is, and
 * `AuditCoverageTest` enforces that — but `payload` is excluded. The raw
 * delivery is already stored here in full; copying it into `pas_audit_logs`
 * as well would duplicate a third-party blob into a table auditors export,
 * for no gain. The status transitions, which are what a reader actually wants
 * from an audit row, are recorded normally.
 *
 * @property int $id
 * @property ?int $school_id
 * @property string $provider
 * @property string $external_event_id
 * @property ?string $event_type
 * @property string $status
 * @property ?string $message
 * @property ?int $invoice_id
 * @property ?int $payment_id
 * @property ?array<string, mixed> $payload
 */
final class GatewayWebhookEvent extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    /** Produced a payment. */
    public const STATUS_HANDLED = 'handled';

    /** Understood, and deliberately not acted on — a refund, a failure. */
    public const STATUS_IGNORED = 'ignored';

    /** Arrived, could not be processed. Kept for diagnosis. */
    public const STATUS_FAILED = 'failed';

    protected $table = 'pas_gateway_events';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'provider',
        'external_event_id',
        'event_type',
        'status',
        'message',
        'invoice_id',
        'payment_id',
        'payload',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'invoice_id' => 'integer',
            'payment_id' => 'integer',
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Keep the gateway's raw payload out of the audit trail.
     *
     * It is stored on this row already; a second copy in `pas_audit_logs`
     * would put a third-party blob into a table that gets exported, and the
     * payload can carry whatever the gateway decided to include.
     *
     * @return list<string>
     */
    public function auditExclude(): array
    {
        return ['payload'];
    }

    public function isHandled(): bool
    {
        return $this->status === self::STATUS_HANDLED;
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
