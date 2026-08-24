<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Pas\AccountingPeriodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accounting period — the window a journal entry is posted into, and the
 * unit of immutability for the whole ledger.
 *
 * Status machine:
 *   open → closed → open (reopen, audit-stamped)
 *
 * Per `rules/CODING_STANDARDS_LARAVEL.md` §434, a closed period is immutable:
 * nothing may be posted into it, and corrections are made by posting a
 * reversing entry into an open period instead. This model owns the predicate
 * ({@see self::isOpen()}); Slice 2's posting guard is the single place that
 * enforces it.
 *
 * Reopening exists because a genuine closing error is otherwise
 * unrecoverable, but it is the most audit-sensitive action in the module —
 * hence the dedicated `reopened_at` / `reopened_by_user_id` stamps on the row
 * in addition to the normal audit-log entry.
 *
 * @property int $id
 * @property int $school_id
 * @property string $code
 * @property ?string $name
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property ?int $fiscal_year
 * @property string $status
 * @property ?CarbonImmutable $closed_at
 * @property ?int $closed_by_user_id
 * @property ?CarbonImmutable $reopened_at
 * @property ?int $reopened_by_user_id
 */
final class AccountingPeriod extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<AccountingPeriodFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
    ];

    protected $table = 'pas_accounting_periods';

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'code',
        'name',
        'start_date',
        'end_date',
        'fiscal_year',
        'status',
        'closed_at',
        'closed_by_user_id',
        'reopened_at',
        'reopened_by_user_id',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return AccountingPeriodFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'fiscal_year' => 'integer',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'closed_by_user_id' => 'integer',
            'reopened_by_user_id' => 'integer',
        ];
    }

    /**
     * Whether the period accepts postings. The single predicate the Slice 2
     * posting guard consults — do not re-derive from `status` at call sites.
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Whether `$date` falls inside this period, inclusive of both endpoints.
     */
    public function contains(CarbonImmutable $date): bool
    {
        return $date->betweenIncluded($this->start_date, $this->end_date);
    }

    /**
     * Restrict to open periods.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * The period whose date range covers `$date`, if any.
     *
     * Periods are not permitted to overlap (enforced by the FormRequest), so
     * at most one row can match.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCovering(Builder $query, CarbonImmutable $date): Builder
    {
        return $query
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString());
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }
}
