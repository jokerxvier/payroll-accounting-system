<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Actions\Accounting\ApproveInvoice;
use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\Models\User;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Pas\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales invoice issued to a customer, or a purchase bill received from a
 * supplier. One class for both — see the migration docblock for why.
 *
 * Status machine:
 *   draft → approved → sent → partially_paid → paid
 *         ↘ voided        ↘ voided
 *
 * Only a draft is editable. Once approved the document carries a
 * BIR-controlled serial and has hit the ledger, so it is corrected the same
 * way a posted journal entry is: by issuing an offsetting document, never by
 * editing it. A void keeps the number rather than releasing it, because the
 * Bureau expects every serial in an authorised range to be accounted for —
 * including the cancelled ones.
 *
 * The predicates below are the single source of truth for which transition
 * is legal from where. The policy, the controller, and the actions all read
 * them rather than re-deriving from `status` — the pattern
 * {@see JournalEntry} established, and the reason a platform admin cannot
 * edit a posted document despite Gate::before granting every ability.
 *
 * @property int $id
 * @property int $school_id
 * @property string $type
 * @property int $contact_id
 * @property ?string $number
 * @property ?string $reference
 * @property CarbonImmutable $issue_date
 * @property ?CarbonImmutable $due_date
 * @property string $status
 * @property bool $is_vat_inclusive
 * @property int $vatable_sales_centavos
 * @property int $vat_exempt_sales_centavos
 * @property int $zero_rated_sales_centavos
 * @property int $vat_centavos
 * @property int $total_centavos
 * @property int $amount_paid_centavos
 * @property ?string $notes
 * @property ?string $terms
 * @property ?int $journal_entry_id
 * @property ?CarbonImmutable $approved_at
 * @property ?int $approved_by_user_id
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $voided_at
 * @property ?int $voided_by_user_id
 * @property ?string $void_reason
 */
final class Invoice extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** Accounts receivable — issued by the school to a customer. */
    public const TYPE_SALES = 'sales';

    /** Accounts payable — received by the school from a supplier. */
    public const TYPE_PURCHASE = 'purchase';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_SALES,
        self::TYPE_PURCHASE,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOIDED = 'voided';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_SENT,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID,
        self::STATUS_VOIDED,
    ];

    /**
     * Statuses in which the document has been issued and posted — everything
     * past approval that has not been voided.
     *
     * @var list<string>
     */
    public const ISSUED_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_SENT,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID,
    ];

    protected $table = 'pas_invoices';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'type',
        'contact_id',
        'number',
        'reference',
        'issue_date',
        'due_date',
        'status',
        'is_vat_inclusive',
        'vatable_sales_centavos',
        'vat_exempt_sales_centavos',
        'zero_rated_sales_centavos',
        'vat_centavos',
        'total_centavos',
        'amount_paid_centavos',
        'notes',
        'terms',
        'journal_entry_id',
        'approved_at',
        'approved_by_user_id',
        'sent_at',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return InvoiceFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'contact_id' => 'integer',
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'is_vat_inclusive' => 'boolean',
            'vatable_sales_centavos' => 'integer',
            'vat_exempt_sales_centavos' => 'integer',
            'zero_rated_sales_centavos' => 'integer',
            'vat_centavos' => 'integer',
            'total_centavos' => 'integer',
            'amount_paid_centavos' => 'integer',
            'journal_entry_id' => 'integer',
            'approved_at' => 'immutable_datetime',
            'approved_by_user_id' => 'integer',
            'sent_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'voided_by_user_id' => 'integer',
        ];
    }

    /* ── Type ───────────────────────────────────────────────────────── */

    public function isSales(): bool
    {
        return $this->type === self::TYPE_SALES;
    }

    public function isPurchase(): bool
    {
        return $this->type === self::TYPE_PURCHASE;
    }

    /* ── Status predicates ──────────────────────────────────────────── */

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    /**
     * True once the document has been issued to a third party and posted.
     */
    public function isIssued(): bool
    {
        return in_array($this->status, self::ISSUED_STATUSES, true);
    }

    /**
     * Whether the document may still be edited or deleted.
     *
     * Read this rather than comparing statuses. Gate::before grants a
     * platform admin every ability, so authorization alone cannot protect an
     * issued document — controllers apply this predicate BEFORE the policy
     * and abort outright.
     */
    public function isMutable(): bool
    {
        return $this->isDraft();
    }

    /**
     * A draft with at least one line and a non-zero total can be approved.
     *
     * The line check is deliberately not folded in here — it needs a query,
     * and a predicate that silently hits the database is a predicate people
     * stop trusting. {@see ApproveInvoice} asserts it.
     */
    public function isApprovable(): bool
    {
        return $this->isDraft() && $this->total_centavos > 0;
    }

    /**
     * An issued document can be voided; a draft is deleted instead, and an
     * already-voided one is done.
     */
    public function isVoidable(): bool
    {
        return $this->isIssued();
    }

    /* ── Money ──────────────────────────────────────────────────────── */

    public function total(): Money
    {
        return Money::fromCentavos($this->total_centavos);
    }

    public function amountPaid(): Money
    {
        return Money::fromCentavos($this->amount_paid_centavos);
    }

    public function balanceDue(): Money
    {
        return Money::fromCentavos($this->total_centavos - $this->amount_paid_centavos);
    }

    /**
     * The invariant every calculated invoice must satisfy: the printed total
     * is exactly the three sales buckets plus the tax.
     *
     * A false here means the calculator disagrees with itself, so the
     * posting service checks it before building a journal entry rather than
     * letting PostJournalEntry reject an unbalanced result with a less
     * specific message.
     */
    public function totalsAreConsistent(): bool
    {
        return $this->total_centavos === $this->vatable_sales_centavos
            + $this->vat_exempt_sales_centavos
            + $this->zero_rated_sales_centavos
            + $this->vat_centavos;
    }

    /* ── Scopes ─────────────────────────────────────────────────────── */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereIn('status', self::ISSUED_STATUSES);
    }

    /**
     * Issued documents that still owe money — the basis of AR/AP ageing.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->issued()->whereColumn('amount_paid_centavos', '<', 'total_centavos');
    }

    /* ── Relations ──────────────────────────────────────────────────── */

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_number');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}
