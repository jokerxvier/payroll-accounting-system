<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\ValueObjects\PayPeriodInput;
use Carbon\CarbonImmutable;
use Database\Factories\Pas\PayrollAdjustmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-off payroll adjustment — a bonus, a back-pay correction, an ad-hoc
 * deduction. Recurring allowances and deductions live on their own
 * subscription tables; this table is for lines that fire on a single
 * payroll period and never repeat.
 *
 * `kind` partitions additions vs deductions. `is_taxable` independently
 * controls whether the line folds into the BIR taxable base.
 *
 * Period matching is by date (decision 1 in the Week 7 plan):
 * `pas_pay_periods` does not exist until Week 9, so the compute path
 * resolves an adjustment to a period via
 * `period.start() <= applies_on <= period.end()` (both ends inclusive).
 *
 * @property int $id
 * @property int $employee_profile_id
 * @property string $kind
 * @property bool $is_taxable
 * @property int $amount_centavos
 * @property CarbonImmutable $applies_on
 * @property string $label
 * @property ?string $reason
 * @property ?int $applied_pay_period_id
 */
final class PayrollAdjustment extends Model
{
    use Auditable;

    /** @use HasFactory<PayrollAdjustmentFactory> */
    use HasFactory;

    public const KIND_ADDITION = 'addition';

    public const KIND_DEDUCTION = 'deduction';

    public const KINDS = [
        self::KIND_ADDITION,
        self::KIND_DEDUCTION,
    ];

    protected $table = 'pas_payroll_adjustments';

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return PayrollAdjustmentFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'employee_profile_id',
        'kind',
        'is_taxable',
        'amount_centavos',
        'applies_on',
        'label',
        'reason',
        'applied_pay_period_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employee_profile_id' => 'integer',
            'is_taxable' => 'boolean',
            'amount_centavos' => 'integer',
            'applies_on' => 'immutable_date',
            'applied_pay_period_id' => 'integer',
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
     * Filter to adjustments whose `applies_on` date falls inside the given
     * period — inclusive on both ends. An adjustment dated exactly on the
     * period's start day OR exactly on its end day belongs to that period;
     * dates one day off either side are excluded.
     *
     * Both ends inclusive matches PH practice: a "May 15" adjustment is
     * meant for the May 1–15 first-half cutoff, not the May 16–31 cutoff.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPeriod(Builder $query, PayPeriodInput $period): Builder
    {
        return $query
            ->whereDate('applies_on', '>=', $period->start())
            ->whereDate('applies_on', '<=', $period->end());
    }
}
