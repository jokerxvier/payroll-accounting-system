<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use App\Concerns\BelongsToTenant;
use App\ValueObjects\PayPeriodInput;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Pas\EmployeeAllowanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-employee subscription to an allowance type.
 *
 * Effective-dated: a subscription is active for periods whose computation
 * date falls in `[effective_from, effective_to)` — `effective_to` is
 * **exclusive** on the boundary day, mirroring `EmployeeDeduction` and
 * `StatutoryContribution::scopeForDate`. Closing a subscription sets
 * `effective_to` rather than deleting so historical payslips remain
 * reproducible.
 *
 * `schedule` controls which payroll runs the allowance applies to. The
 * matrix is identical to `EmployeeDeduction::scopeForSchedule` and is
 * delegated to that class to keep one definition.
 *
 * One-off allowances ride on `pas_payroll_adjustments` instead of this
 * table.
 *
 * @property int $id
 * @property int $employee_profile_id
 * @property int $allowance_id
 * @property int $amount_centavos
 * @property string $schedule
 * @property CarbonImmutable $effective_from
 * @property ?CarbonImmutable $effective_to
 * @property ?string $notes
 */
final class EmployeeAllowance extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<EmployeeAllowanceFactory> */
    use HasFactory;

    protected $table = 'pas_employee_allowances';

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return EmployeeAllowanceFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'school_id',
        'employee_profile_id',
        'allowance_id',
        'amount_centavos',
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
            'allowance_id' => 'integer',
            'amount_centavos' => 'integer',
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
     * @return BelongsTo<Allowance, $this>
     */
    public function allowance(): BelongsTo
    {
        return $this->belongsTo(Allowance::class);
    }

    /**
     * Filter to subscriptions whose effective range covers the given date.
     * `effective_from` inclusive, `effective_to` exclusive — same convention
     * as `EmployeeDeduction::scopeActiveOn`.
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
     * Filter to subscriptions whose `schedule` value applies to the given
     * pay period. Delegates to `EmployeeDeduction::schedulesMatching` so the
     * matrix lives in exactly one place.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSchedule(Builder $query, PayPeriodInput $period): Builder
    {
        return $query->whereIn('schedule', EmployeeDeduction::schedulesMatching($period));
    }
}
