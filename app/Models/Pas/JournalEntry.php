<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\Models\User;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Pas\JournalEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One accounting transaction. Its debit and credit lines live on
 * {@see JournalEntryLine}.
 *
 * Status machine:
 *   draft → pending → posted
 *                 ↘ voided
 *
 * A posted entry is immutable: correcting one means posting a reversing
 * entry, never editing the original (`rules/CODING_STANDARDS_LARAVEL.md`
 * §471). It also stays `posted` — the original and its reversal offset each
 * other, so a report that reads posted entries sees the correction happen
 * rather than seeing the original vanish. `voided` is reserved for
 * abandoning an entry that never reached the ledger.
 *
 * The predicates below are the single source of truth for which transition
 * is legal from where; the policy and the actions both read them rather than
 * re-deriving from `status`.
 *
 * @property int $id
 * @property int $school_id
 * @property string $entry_number
 * @property ?int $accounting_period_id
 * @property CarbonImmutable $date
 * @property ?string $reference
 * @property ?string $narration
 * @property string $status
 * @property ?string $source_type
 * @property ?int $source_id
 * @property int $total_debit_centavos
 * @property int $total_credit_centavos
 * @property ?CarbonImmutable $posted_at
 * @property ?int $posted_by_user_id
 * @property ?CarbonImmutable $reversed_at
 * @property ?int $reversed_by_user_id
 * @property ?int $reversal_of_entry_id
 */
final class JournalEntry extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOIDED = 'voided';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_POSTED,
        self::STATUS_VOIDED,
    ];

    /**
     * `source_type` marker for the cutover snapshot written by
     * `PostOpeningBalances`.
     *
     * Every other value in this column is a model FQCN with a matching
     * `source_id` — an Invoice, a Payment, a PayrollRun. An opening balance
     * has no such source: it describes what the books already said before
     * this system existed, so there is no row here to point at and
     * `source_id` stays null. A sentinel rather than a new boolean column
     * because the question it answers ("where did this entry come from?") is
     * the one `source_type` already exists to answer, and nothing morphs the
     * column into a class.
     */
    public const SOURCE_OPENING_BALANCE = 'opening-balance';

    protected $table = 'pas_journal_entries';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'entry_number',
        'accounting_period_id',
        'date',
        'reference',
        'narration',
        'status',
        'source_type',
        'source_id',
        'total_debit_centavos',
        'total_credit_centavos',
        'posted_at',
        'posted_by_user_id',
        'reversed_at',
        'reversed_by_user_id',
        'reversal_of_entry_id',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return JournalEntryFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'accounting_period_id' => 'integer',
            'date' => 'immutable_date',
            'source_id' => 'integer',
            'total_debit_centavos' => 'integer',
            'total_credit_centavos' => 'integer',
            'posted_at' => 'immutable_datetime',
            'posted_by_user_id' => 'integer',
            'reversed_at' => 'immutable_datetime',
            'reversed_by_user_id' => 'integer',
            'reversal_of_entry_id' => 'integer',
        ];
    }

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
     * Whether a reversing entry has already been posted against this one.
     *
     * Reads the stamp rather than counting `reversalEntries()` so the check costs
     * nothing on a loaded model — the two are written together in the same
     * transaction.
     */
    public function hasBeenReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * Whether the entry and its lines can still be edited. Only ever true
     * before it has been posted — once posted the figures are history.
     */
    public function isMutable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING], true);
    }

    /** Submitting for approval is legal only from `draft`. */
    public function isSubmittable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Posting is legal from `draft` or `pending`. */
    public function isPostable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING], true);
    }

    /**
     * Whether a reversing entry may be posted against this one.
     *
     * Legal only for a posted entry, and only once. A draft is deleted
     * instead — there is nothing in the ledger to unwind. Reversing twice
     * would post a second offsetting entry and overshoot, leaving the
     * account wrong by the full amount in the other direction.
     */
    public function isReversible(): bool
    {
        return $this->status === self::STATUS_POSTED && ! $this->hasBeenReversed();
    }

    /** True when this entry was created to reverse another one. */
    public function isReversal(): bool
    {
        return $this->reversal_of_entry_id !== null;
    }

    /**
     * Whether the entry's own denormalised totals balance.
     *
     * This reports on what is already stored. `PostJournalEntry` computes the
     * totals from the lines and asserts the same equality before persisting —
     * this method is for reading back a stored entry, not for validating one.
     */
    public function isBalanced(): bool
    {
        return $this->total_debit_centavos === $this->total_credit_centavos;
    }

    public function totalDebit(): Money
    {
        return Money::fromCentavos($this->total_debit_centavos);
    }

    public function totalCredit(): Money
    {
        return Money::fromCentavos($this->total_credit_centavos);
    }

    /**
     * Restrict to entries that actually moved the ledger.
     *
     * Every financial report reads through this.
     *
     * A reversed entry deliberately stays in scope: it and its reversal are
     * both posted and cancel each other out. Dropping the original would
     * leave the reversal unmatched and understate the account by the full
     * amount. Only drafts, pending entries, and never-posted `voided` ones
     * are excluded — none of them ever reached the ledger.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPeriod(Builder $query, int $accountingPeriodId): Builder
    {
        return $query->where('accounting_period_id', $accountingPeriodId);
    }

    /**
     * The cutover snapshot, posted or otherwise.
     *
     * Scoped rather than left to callers because "is there already an
     * opening balance for this school?" is asked from three places — the
     * import preview, the posting action's one-snapshot guard, and the
     * report note — and three hand-written `where` clauses on a sentinel
     * string is how one of them ends up spelling it differently.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpeningBalance(Builder $query): Builder
    {
        return $query->where('source_type', self::SOURCE_OPENING_BALANCE);
    }

    public function isOpeningBalance(): bool
    {
        return $this->source_type === self::SOURCE_OPENING_BALANCE;
    }

    /** @return HasMany<JournalEntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_number');
    }

    /** @return BelongsTo<AccountingPeriod, $this> */
    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    /** @return BelongsTo<self, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_entry_id');
    }

    /**
     * Entries posted to reverse this one. At most one in practice —
     * {@see self::isReversible()} refuses a second.
     *
     * @return HasMany<self, $this>
     */
    public function reversalEntries(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }
}
