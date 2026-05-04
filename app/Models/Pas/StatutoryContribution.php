<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\StatutoryContributionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned, effective-dated statutory contribution rule set.
 *
 * One row per (contribution_code, effective period). The `algorithm` column
 * names a strategy class that knows how to read the `rules` JSON payload and
 * produce a StatutoryContributionResult. Adding a new contribution type or
 * jurisdiction is a new row, not a schema change.
 *
 * Boundary semantics: `effective_to` is **exclusive** — a row is active for
 * dates `effective_from <= d < effective_to` (or open-ended when null). This
 * matches the supersession invariant: when a new version's effective_from is
 * set to the prior row's effective_to, both never overlap.
 *
 * @phpstan-type RulesShape array<string, mixed>
 *
 * @property int $id
 * @property string $contribution_code
 * @property string $label
 * @property string $algorithm
 * @property int $application_order
 * @property string $applies_to
 * @property CarbonImmutable $effective_from
 * @property ?CarbonImmutable $effective_to
 * @property ?CarbonImmutable $voided_at
 * @property ?int $voided_by_user_id
 * @property RulesShape $rules
 * @property ?string $notes
 */
final class StatutoryContribution extends Model
{
    use Auditable;

    /** @use HasFactory<StatutoryContributionFactory> */
    use HasFactory;

    public const ALGORITHM_BRACKET_TABLE = 'bracket_table';

    public const ALGORITHM_SALARY_BAND = 'salary_band';

    public const ALGORITHM_PERCENTAGE_WITH_CAP = 'percentage_with_cap';

    public const ALGORITHM_TIERED_PERCENTAGE = 'tiered_percentage';

    public const ALGORITHM_FLAT_PERCENTAGE = 'flat_percentage';

    public const ALGORITHMS = [
        self::ALGORITHM_BRACKET_TABLE,
        self::ALGORITHM_SALARY_BAND,
        self::ALGORITHM_PERCENTAGE_WITH_CAP,
        self::ALGORITHM_TIERED_PERCENTAGE,
        self::ALGORITHM_FLAT_PERCENTAGE,
    ];

    public const CODE_BIR = 'BIR_WITHHOLDING';

    public const CODE_SSS = 'SSS';

    public const CODE_PHILHEALTH = 'PHILHEALTH';

    public const CODE_PAGIBIG = 'PAGIBIG';

    /**
     * Generic municipal/local tax slot (e.g. Quezon City local tax). Reserved
     * for the {@see self::ALGORITHM_FLAT_PERCENTAGE} algorithm, which models
     * single-rate contributions outside the four PH statutory schemes.
     */
    public const CODE_CITY_TAX = 'CITY_TAX';

    public const CODES = [
        self::CODE_BIR,
        self::CODE_SSS,
        self::CODE_PHILHEALTH,
        self::CODE_PAGIBIG,
        self::CODE_CITY_TAX,
    ];

    public const APPLIES_TO_GROSS_PAY = 'gross_pay';

    public const APPLIES_TO_TAXABLE_INCOME = 'taxable_income';

    public const APPLIES_TO = [
        self::APPLIES_TO_GROSS_PAY,
        self::APPLIES_TO_TAXABLE_INCOME,
    ];

    protected $table = 'pas_statutory_contributions';

    /**
     * Factory lives at Database\Factories\StatutoryContributionFactory, which
     * doesn't match Laravel's default resolver path for a model under the
     * App\Models\Pas namespace. Same pattern as EmployeeProfile.
     */
    protected static function newFactory(): Factory
    {
        return StatutoryContributionFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'contribution_code',
        'label',
        'algorithm',
        'application_order',
        'applies_to',
        'effective_from',
        'effective_to',
        'voided_at',
        'voided_by_user_id',
        'rules',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'application_order' => 'integer',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'voided_at' => 'immutable_datetime',
            'voided_by_user_id' => 'integer',
            'rules' => 'array',
        ];
    }

    /**
     * Actor who retired this contribution row, if any. Nullable: when the
     * underlying LMS user is deleted the FK is set to NULL (preserving the
     * `voided_at` timestamp as the audit fact) rather than orphaning the row.
     *
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /**
     * Restrict a query to live (non-voided) rows. Used directly by admin
     * surfaces that intentionally want to hide voided rows; also chained
     * inside {@see scopeForDate} so every computation path automatically
     * skips them.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /**
     * Filter rows whose effective range covers the given date. Optionally
     * narrow to a single contribution_code.
     *
     * Boundary: `effective_to` is exclusive — a row with effective_to=2024-12-31
     * is NOT active on 2024-12-31; the next version (effective_from=2025-01-01)
     * is. This pairs with the supersession invariant in the admin UI.
     *
     * Voided rows are excluded by chaining `notVoided()` here. Every
     * computation path (registry, `current()`, payroll engine) routes
     * through this scope, so a void is a hard cutoff for participation in
     * payroll math without relying on each call site to remember.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDate(Builder $query, CarbonInterface $date, ?string $contributionCode = null): Builder
    {
        $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>', $date),
            )
            ->notVoided();

        if ($contributionCode !== null) {
            $query->where('contribution_code', $contributionCode);
        }

        return $query;
    }

    /**
     * Return the single row currently active for the given contribution code +
     * date, or null if none exist. Convenience wrapper around scopeForDate.
     */
    public static function current(string $contributionCode, CarbonInterface $date): ?self
    {
        return self::query()->forDate($date, $contributionCode)->first();
    }

    /**
     * Order rows by their configured application sequence, then by code as a
     * deterministic tiebreaker. The payroll engine relies on this ordering to
     * compute deductions in the canonical PH sequence (SSS → PhilHealth →
     * Pag-IBIG → BIR).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderedForApplication(Builder $query): Builder
    {
        return $query
            ->orderBy('application_order')
            ->orderBy('contribution_code');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Retire this contribution row. Sets `voided_at` to now and stamps the
     * actor. Persists immediately so the audit observer fires a single
     * `updated` row capturing the void transition. After this returns,
     * `isEditable()` is false and the registry / `current()` will skip the
     * row on every future date lookup.
     */
    public function void(int $userId): void
    {
        $this->forceFill([
            'voided_at' => CarbonImmutable::now(),
            'voided_by_user_id' => $userId,
        ])->save();
    }

    /**
     * Whether this row may still be mutated in place.
     *
     * A row is editable iff it is strictly future-dated, open-ended, and
     * not voided. Once `effective_from` passes, the row may have computed
     * payslips (Phase 3+ pas_payslips table) or simply be live for new
     * computations — either way, treat as immutable for audit safety.
     * Likewise an `effective_to` set implies the row has been superseded
     * and a later version owns the live behavior; mutating the historical
     * row would silently rewrite history.
     */
    public function isEditable(): bool
    {
        $today = CarbonImmutable::now()->startOfDay();

        return $this->effective_from->greaterThan($today)
            && $this->effective_to === null
            && $this->voided_at === null;
    }
}
