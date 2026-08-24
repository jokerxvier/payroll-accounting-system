<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\Models\User;
use App\Services\Accounting\InvoiceBalanceService;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Pas\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received from a customer, or paid out to a supplier.
 *
 * Status machine:
 *   draft → posted
 *         ↘ voided (from posted only)
 *
 * A draft is a payment somebody has keyed but not committed: it allocates
 * nothing and touches no invoice balance. Posting is what makes it real —
 * {@see InvoiceBalanceService} counts allocations
 * only from posted payments, so a forgotten draft can never quietly mark an
 * invoice paid.
 *
 * A posted payment is corrected by voiding, which reverses its journal entry
 * and restores every balance it touched. Its allocations are kept rather than
 * deleted — they are the record of what was applied, and they stop counting
 * on their own once the payment is no longer posted.
 *
 * @property int $id
 * @property int $school_id
 * @property string $type
 * @property int $contact_id
 * @property CarbonImmutable $payment_date
 * @property int $amount_centavos
 * @property int $allocated_centavos
 * @property int $cash_account_id
 * @property string $method
 * @property ?string $reference
 * @property ?string $notes
 * @property string $status
 * @property ?int $journal_entry_id
 * @property ?CarbonImmutable $posted_at
 * @property ?int $posted_by_user_id
 * @property ?CarbonImmutable $voided_at
 * @property ?int $voided_by_user_id
 * @property ?string $void_reason
 */
final class Payment extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /** Money in, settling sales invoices. */
    public const TYPE_RECEIPT = 'receipt';

    /** Money out, settling supplier bills. */
    public const TYPE_DISBURSEMENT = 'disbursement';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_RECEIPT,
        self::TYPE_DISBURSEMENT,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOIDED = 'voided';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_POSTED,
        self::STATUS_VOIDED,
    ];

    public const METHOD_CASH = 'cash';

    public const METHOD_CHEQUE = 'cheque';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_ONLINE = 'online';

    public const METHOD_OTHER = 'other';

    /** @var list<string> */
    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_CHEQUE,
        self::METHOD_BANK_TRANSFER,
        self::METHOD_ONLINE,
        self::METHOD_OTHER,
    ];

    protected $table = 'pas_payments';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'type',
        'contact_id',
        'payment_date',
        'amount_centavos',
        'allocated_centavos',
        'cash_account_id',
        'method',
        'reference',
        'notes',
        'status',
        'journal_entry_id',
        'posted_at',
        'posted_by_user_id',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return PaymentFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'contact_id' => 'integer',
            'payment_date' => 'immutable_date',
            'amount_centavos' => 'integer',
            'allocated_centavos' => 'integer',
            'cash_account_id' => 'integer',
            'journal_entry_id' => 'integer',
            'posted_at' => 'immutable_datetime',
            'posted_by_user_id' => 'integer',
            'voided_at' => 'immutable_datetime',
            'voided_by_user_id' => 'integer',
        ];
    }

    /* ── Type ───────────────────────────────────────────────────────── */

    public function isReceipt(): bool
    {
        return $this->type === self::TYPE_RECEIPT;
    }

    public function isDisbursement(): bool
    {
        return $this->type === self::TYPE_DISBURSEMENT;
    }

    /**
     * Which kind of document this payment can settle.
     *
     * A receipt pays sales invoices and a disbursement pays bills. Crossing
     * them would credit a receivable with money that went out of the door.
     */
    public function settlesInvoiceType(): string
    {
        return $this->isReceipt() ? Invoice::TYPE_SALES : Invoice::TYPE_PURCHASE;
    }

    /* ── Status predicates ──────────────────────────────────────────── */

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    /**
     * Whether the payment may still be edited or deleted.
     *
     * Read this rather than comparing statuses. `Gate::before` grants a
     * platform admin every ability, so authorization alone cannot protect a
     * posted payment — controllers apply this predicate BEFORE the policy and
     * abort outright.
     */
    public function isMutable(): bool
    {
        return $this->isDraft();
    }

    public function isPostable(): bool
    {
        return $this->isDraft() && $this->amount_centavos > 0;
    }

    public function isVoidable(): bool
    {
        return $this->isPosted();
    }

    /* ── Money ──────────────────────────────────────────────────────── */

    public function amount(): Money
    {
        return Money::fromCentavos($this->amount_centavos);
    }

    public function allocated(): Money
    {
        return Money::fromCentavos($this->allocated_centavos);
    }

    /**
     * Money this payment carries that no invoice has claimed.
     *
     * Posts to the advances account rather than pushing the control account
     * negative — an advance is a liability owed back in goods, not a
     * receivable owed to us.
     */
    public function unallocated(): Money
    {
        return Money::fromCentavos($this->amount_centavos - $this->allocated_centavos);
    }

    public function isFullyAllocated(): bool
    {
        return $this->allocated_centavos === $this->amount_centavos;
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
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    /* ── Relations ──────────────────────────────────────────────────── */

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<ChartOfAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_account_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}
