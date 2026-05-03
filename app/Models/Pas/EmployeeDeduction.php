<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\ValueObjects\PayPeriodInput;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Pas\EmployeeDeductionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Per-employee subscription to a custom deduction type.
 *
 * Exactly one of `amount_centavos` or `percent_basis_points` is populated,
 * matching the parent `pas_deduction_types.calc_method`. `percent_basis_points`
 * is integer BPs — 250 = 2.50%, 1_000 = 10.00%.
 *
 * Effective-dated subscription: active when `effective_from <= date AND
 * (effective_to IS NULL OR effective_to > date)` — `effective_to` is
 * **exclusive** on the boundary day, mirroring
 * `StatutoryContribution::scopeForDate`.
 *
 * @property int $id
 * @property int $employee_profile_id
 * @property int $deduction_type_id
 * @property ?int $amount_centavos
 * @property ?int $percent_basis_points
 * @property string $schedule
 * @property CarbonImmutable $effective_from
 * @property ?CarbonImmutable $effective_to
 * @property ?string $notes
 */
final class EmployeeDeduction extends Model
{
    use Auditable;

    /** @use HasFactory<EmployeeDeductionFactory> */
    use HasFactory;

    public const SCHEDULE_EVERY_RUN = 'every_run';

    public const SCHEDULE_FIRST_HALF = 'first_half';

    public const SCHEDULE_SECOND_HALF = 'second_half';

    public const SCHEDULE_MONTHLY_FIRST = 'monthly_first';

    public const SCHEDULE_MONTHLY_LAST = 'monthly_last';

    public const SCHEDULES = [
        self::SCHEDULE_EVERY_RUN,
        self::SCHEDULE_FIRST_HALF,
        self::SCHEDULE_SECOND_HALF,
        self::SCHEDULE_MONTHLY_FIRST,
        self::SCHEDULE_MONTHLY_LAST,
    ];

    protected $table = 'pas_employee_deductions';

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return EmployeeDeductionFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'employee_profile_id',
        'deduction_type_id',
        'amount_centavos',
        'percent_basis_points',
        'schedule',
        'effective_from',
        'effective_to',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employee_profile_id' => 'integer',
            'deduction_type_id' => 'integer',
            'amount_centavos' => 'integer',
            'percent_basis_points' => 'integer',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }

    /**
     * Owning employee profile.
     *
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /**
     * Catalog row this subscription points at.
     *
     * @return BelongsTo<DeductionType, $this>
     */
    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class);
    }

    /**
     * Filter to subscriptions whose effective range covers the given date.
     *
     * Boundary semantics match `StatutoryContribution::scopeForDate`:
     * `effective_from` is inclusive, `effective_to` is exclusive. A row with
     * effective_to=2026-05-31 is NOT active on 2026-05-31; the next
     * subscription (effective_from=2026-06-01) is.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $date): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>', $date),
            );
    }

    /**
     * Filter to subscriptions whose `schedule` value applies to the given pay
     * period. The matrix below reflects how the compute path decides whether
     * a recurring deduction fires on a particular run.
     *
     * Schedule × frequency matrix:
     *
     *   schedule         | monthly run | semi-monthly first half | semi-monthly second half
     *   -----------------+-------------+-------------------------+--------------------------
     *   every_run        |     yes     |          yes            |           yes
     *   first_half       |     no      |          yes            |           no
     *   second_half      |     no      |          no             |           yes
     *   monthly_first    |     yes     |          yes            |           no
     *   monthly_last     |     yes     |          no             |           yes
     *
     * Rationale:
     *  - every_run is the default and unconditional.
     *  - first_half / second_half are valid only on a semi-monthly run; on a
     *    monthly run the subscription does NOT fire (the user explicitly
     *    asked for half-month behavior).
     *  - monthly_first / monthly_last describe "once a month, in the first
     *    or second half of the cutoff cycle". On a monthly frequency both
     *    map to the single monthly run; on a semi-monthly frequency they
     *    pick out one of the two halves.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSchedule(Builder $query, PayPeriodInput $period): Builder
    {
        return $query->whereIn('schedule', self::schedulesMatching($period));
    }

    /**
     * Resolve the list of `schedule` enum values that apply to the given
     * period. Exposed as a static helper so sibling models
     * (EmployeeAllowance) and the action layer can reuse the same matrix
     * without re-implementing it.
     *
     * @return list<string>
     */
    public static function schedulesMatching(PayPeriodInput $period): array
    {
        if ($period->isMonthly()) {
            return [
                self::SCHEDULE_EVERY_RUN,
                self::SCHEDULE_MONTHLY_FIRST,
                self::SCHEDULE_MONTHLY_LAST,
            ];
        }

        if ($period->isSemiMonthly()) {
            // The semi-monthly factories normalise start to day 1 (first half)
            // or day 16 (second half). Use the start-of-month day to decide.
            $isFirstHalf = $period->start()->day === 1;

            return $isFirstHalf
                ? [
                    self::SCHEDULE_EVERY_RUN,
                    self::SCHEDULE_FIRST_HALF,
                    self::SCHEDULE_MONTHLY_FIRST,
                ]
                : [
                    self::SCHEDULE_EVERY_RUN,
                    self::SCHEDULE_SECOND_HALF,
                    self::SCHEDULE_MONTHLY_LAST,
                ];
        }

        throw new InvalidArgumentException(
            'EmployeeDeduction::schedulesMatching cannot resolve schedules for unknown frequency: '
            .$period->frequency()
        );
    }
}
