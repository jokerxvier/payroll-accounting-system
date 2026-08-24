<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Actions\Payroll\PostPayrollRunAction;
use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\PayrollRunFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single "Generate payroll" execution against a pay period.
 *
 * Status machine (enforced at the action layer in Stage B):
 *   draft → computing → computed → pending_approval → approved → posted
 *                                                       ↘ voided
 *
 * Totals are denormalised so the run-detail page can render headlines without
 * aggregating every payslip on each reload. They are written by the queued
 * generator (Stage B) and only by it; never edited by users.
 *
 * @property int $id
 * @property int $pay_period_id
 * @property string $status
 * @property int $total_employees
 * @property int $total_employee_deductions_centavos
 * @property int $total_employer_contributions_centavos
 * @property int $total_net_pay_centavos
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $computed_at
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PayrollRun extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_COMPUTING = 'computing';

    public const STATUS_COMPUTED = 'computed';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOIDED = 'voided';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_COMPUTING,
        self::STATUS_COMPUTED,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_POSTED,
        self::STATUS_VOIDED,
    ];

    protected $table = 'pas_payroll_runs';

    protected $fillable = [
        'school_id',
        'pay_period_id',
        'status',
        'total_employees',
        'total_employee_deductions_centavos',
        'total_employer_contributions_centavos',
        'total_net_pay_centavos',
        'started_at',
        'computed_at',
        'submitted_at',
        'submitted_by_user_id',
        'approved_at',
        'approved_by_user_id',
        'posted_at',
        'posted_by_user_id',
        'voided_at',
        'voided_by_user_id',
        'bulk_pdf_zip_path',
        'bulk_pdf_built_at',
        'posting_payload',
        'journal_entry_id',
        'ledger_posted_at',
    ];

    protected static function newFactory(): Factory
    {
        return PayrollRunFactory::new();
    }

    protected function casts(): array
    {
        return [
            'pay_period_id' => 'integer',
            'total_employees' => 'integer',
            'total_employee_deductions_centavos' => 'integer',
            'total_employer_contributions_centavos' => 'integer',
            'total_net_pay_centavos' => 'integer',
            'started_at' => 'immutable_datetime',
            'computed_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'bulk_pdf_built_at' => 'immutable_datetime',
            'posting_payload' => 'array',
            'journal_entry_id' => 'integer',
            'ledger_posted_at' => 'immutable_datetime',
            'submitted_by_user_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'posted_by_user_id' => 'integer',
            'voided_by_user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PayPeriod, PayrollRun> */
    public function payPeriod(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class);
    }

    /** @return HasMany<Payslip> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * The journal entry this run posted to the ledger, if it reached it.
     *
     * Null when the run predates Phase 5, or when the ledger posting was
     * refused (a closed period, a missing mapped account) — payroll is not
     * blocked on the books being open, so the run can post without an entry
     * and be retried later.
     *
     * @return BelongsTo<JournalEntry, PayrollRun>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** Whether this run has reached the general ledger. */
    public function hasReachedLedger(): bool
    {
        return $this->journal_entry_id !== null;
    }

    /** @return BelongsTo<User, PayrollRun> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** @return BelongsTo<User, PayrollRun> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return BelongsTo<User, PayrollRun> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /** @return BelongsTo<User, PayrollRun> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Whether the run can still be re-computed or edited. Re-computation is
     * only legal in `draft` or `computed` states. Once it enters
     * pending_approval / approved / posted / voided, it's frozen.
     */
    public function isMutable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_COMPUTED], true)
            && ! $this->isVoided();
    }

    /**
     * Whether the run is in a terminal/locked state — approved, posted, or
     * voided. Locked runs cannot be edited, re-run, or transitioned further
     * (except `posted` is the absolute final state; `approved` can still
     * progress to `posted` or backwards to `voided`).
     */
    public function isLocked(): bool
    {
        return in_array(
            $this->status,
            [self::STATUS_APPROVED, self::STATUS_POSTED, self::STATUS_VOIDED],
            true,
        );
    }

    /**
     * Whether the run may be submitted for approval — legal only from
     * `computed`. Single source of truth for the submit transition rule,
     * shared by the policy and the controller's `can` flags.
     */
    public function isSubmittable(): bool
    {
        return $this->status === self::STATUS_COMPUTED;
    }

    /**
     * Whether the run may be approved — legal only from `pending_approval`.
     */
    public function isApprovable(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    /**
     * Whether the run may be posted. Normally legal only from `approved`.
     *
     * DEMO: the approval step is bypassed, so a `computed` run can be posted
     * directly. To restore the maker-checker flow, drop STATUS_COMPUTED here
     * and from {@see PostPayrollRunAction}.
     */
    public function isPostable(): bool
    {
        return in_array($this->status, [self::STATUS_COMPUTED, self::STATUS_APPROVED], true);
    }

    /**
     * Whether the run may be voided — legal for any non-posted, non-voided
     * run. `posted` is the absolute final state; a `voided` run is already
     * unwound.
     */
    public function isVoidable(): bool
    {
        return $this->status !== self::STATUS_POSTED
            && $this->status !== self::STATUS_VOIDED;
    }
}
